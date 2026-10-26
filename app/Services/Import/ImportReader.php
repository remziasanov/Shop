<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\CategorySheetField;
use App\Exceptions\ImportFileException;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Streaming reader of .xlsx and .csv import files (IM-01, D-034). Memory use does not depend on file size.
 *
 * Rows are yielded as `list<string|null>` (blank cell = null, values trimmed) and keyed by the 1-based row
 * number in the source file; the first yielded row is the header row. Fully blank rows are skipped, so keys
 * may have gaps. CSV: UTF-8 (BOM removed) or Windows-1251 (transcoded by streaming into a temporary UTF-8
 * file that is removed when iteration ends), delimiter ";" or ",".
 */
final class ImportReader
{
    public const CSV_SHEET = 'CSV';

    /** Bytes inspected to detect the CSV encoding and delimiter. */
    private const SAMPLE_BYTES = 65536;

    private const TRANSCODE_CHUNK = 1048576;

    /**
     * Sheet names; `['CSV']` for a CSV file.
     *
     * @return list<string>
     *
     * @throws ImportFileException
     */
    public function sheets(string $path): array
    {
        if ($this->detectType($path) === 'csv') {
            return [self::CSV_SHEET];
        }

        $reader = $this->xlsxReader($path);
        $names = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $names[] = $sheet->getName();
            }
        } catch (Throwable $e) {
            throw $this->unreadable($e);
        } finally {
            $reader->close();
        }

        return $names;
    }

    public function hasSheet(string $path, string $sheet = CategorySheetField::SHEET_NAME): bool
    {
        return in_array($sheet, $this->sheets($path), true);
    }

    /**
     * The sheet with products: the first one that is not «Категории» or «Справка».
     *
     * @param  list<string>  $sheets
     */
    public function guessProductSheet(array $sheets): ?string
    {
        foreach ($sheets as $sheet) {
            if (! in_array(mb_strtolower($sheet), [mb_strtolower(CategorySheetField::SHEET_NAME), 'справка'], true)) {
                return $sheet;
            }
        }

        return $sheets[0] ?? null;
    }

    /**
     * Stream the rows of a sheet (null = the first sheet; ignored for CSV). Keys are source row numbers.
     *
     * @return Generator<int, list<string|null>>
     *
     * @throws ImportFileException
     */
    public function open(string $path, ?string $sheet = null): Generator
    {
        if ($this->detectType($path) === 'csv') {
            yield from $this->readCsv($path);

            return;
        }

        yield from $this->readXlsx($path, $sheet);
    }

    /**
     * Header row of a sheet; empty list when the sheet has no rows.
     *
     * @return list<string|null>
     */
    public function headers(string $path, ?string $sheet = null): array
    {
        foreach ($this->open($path, $sheet) as $row) {
            return $row;
        }

        return [];
    }

    /**
     * Header row and up to $limit data rows (for the mapping step).
     *
     * @return array{headers: list<string|null>, rows: array<int, list<string|null>>}
     */
    public function sample(string $path, ?string $sheet = null, int $limit = 5): array
    {
        $headers = [];
        $rows = [];
        $first = true;

        foreach ($this->open($path, $sheet) as $number => $row) {
            if ($first) {
                $headers = $row;
                $first = false;

                continue;
            }

            $rows[$number] = $row;

            if (count($rows) >= $limit) {
                break;
            }
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    private function readXlsx(string $path, ?string $sheetName): Generator
    {
        $reader = $this->xlsxReader($path);

        try {
            $found = false;

            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheetName !== null && $sheetName !== '' && $sheet->getName() !== $sheetName) {
                    continue;
                }

                $found = true;
                $number = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    $cells = [];

                    foreach ($row->getCells() as $cell) {
                        $cells[] = $this->cellToString($cell);
                    }

                    $cells = $this->trimTrailingBlanks($cells);

                    if ($cells === []) {
                        continue;
                    }

                    yield $number => $cells;
                }

                break;
            }

            if (! $found) {
                throw new ImportFileException(__('import.errors.sheet_not_found', ['sheet' => (string) $sheetName]));
            }
        } catch (ImportFileException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->unreadable($e);
        } finally {
            $reader->close();
        }
    }

    /**
     * @return Generator<int, list<string|null>>
     */
    private function readCsv(string $path): Generator
    {
        $sample = $this->readSample($path);
        $isUtf8 = $this->isUtf8($sample);
        $delimiter = $this->detectDelimiter($isUtf8 ? $sample : $this->toUtf8($sample));
        $tempPath = null;
        $source = $path;

        if (! $isUtf8) {
            $tempPath = $this->transcodeToTempFile($path);
            $source = $tempPath;
        }

        $handle = fopen($source, 'rb');

        if ($handle === false) {
            $this->removeTemp($tempPath);

            throw new ImportFileException(__('import.errors.file_unreadable'));
        }

        try {
            if (fread($handle, 3) !== "\xEF\xBB\xBF") {
                rewind($handle);
            }

            $number = 0;

            while (($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $number++;
                $cells = [];

                foreach ($record as $value) {
                    $cells[] = $this->normalizeText($value);
                }

                $cells = $this->trimTrailingBlanks($cells);

                if ($cells === []) {
                    continue;
                }

                yield $number => $cells;
            }
        } finally {
            fclose($handle);
            $this->removeTemp($tempPath);
        }
    }

    private function xlsxReader(string $path): XlsxReader
    {
        $options = new XlsxOptions;
        // Keep blank rows so that the yielded keys equal the source row numbers.
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $options->setTempFolder(sys_get_temp_dir());

        $reader = new XlsxReader($options);

        try {
            $reader->open($path);
        } catch (Throwable $e) {
            throw $this->unreadable($e);
        }

        return $reader;
    }

    /**
     * 'xlsx' (zip container) or 'csv' (anything else); the old binary .xls is rejected.
     *
     * @throws ImportFileException
     */
    private function detectType(string $path): string
    {
        $handle = is_file($path) ? fopen($path, 'rb') : false;

        if ($handle === false) {
            throw new ImportFileException(__('import.errors.file_unreadable'));
        }

        $magic = (string) fread($handle, 8);
        fclose($handle);

        if (str_starts_with($magic, "PK\x03\x04")) {
            return 'xlsx';
        }

        if (str_starts_with($magic, "\xD0\xCF\x11\xE0")) {
            throw new ImportFileException(__('import.errors.xls_unsupported'));
        }

        return 'csv';
    }

    private function readSample(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new ImportFileException(__('import.errors.file_unreadable'));
        }

        $sample = (string) fread($handle, self::SAMPLE_BYTES);
        fclose($handle);

        return $sample;
    }

    /**
     * Valid UTF-8 (a multibyte character cut at the end of the sample is tolerated); pure ASCII counts as UTF-8.
     */
    private function isUtf8(string $sample): bool
    {
        for ($cut = 0; $cut <= 3 && $cut <= strlen($sample); $cut++) {
            if (mb_check_encoding($cut === 0 ? $sample : substr($sample, 0, -$cut), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    private function toUtf8(string $windows1251): string
    {
        return mb_convert_encoding($windows1251, 'UTF-8', 'Windows-1251');
    }

    /**
     * The delimiter (";" or ",") that occurs more often outside quotes in the header line; ";" on a tie.
     */
    private function detectDelimiter(string $utf8Sample): string
    {
        $utf8Sample = str_starts_with($utf8Sample, "\xEF\xBB\xBF") ? substr($utf8Sample, 3) : $utf8Sample;
        $semicolons = 0;
        $commas = 0;
        $inQuotes = false;
        $length = strlen($utf8Sample);

        for ($i = 0; $i < $length; $i++) {
            $char = $utf8Sample[$i];

            if ($char === '"') {
                $inQuotes = ! $inQuotes;
            } elseif (! $inQuotes) {
                if ($char === "\n" || $char === "\r") {
                    break;
                }

                if ($char === ';') {
                    $semicolons++;
                } elseif ($char === ',') {
                    $commas++;
                }
            }
        }

        return $commas > $semicolons ? ',' : ';';
    }

    /**
     * Windows-1251 => UTF-8, streamed in 1 MB blocks (single-byte encoding: block borders are safe).
     */
    private function transcodeToTempFile(string $path): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'shopimp');
        $in = fopen($path, 'rb');
        $out = $tempPath === false ? false : fopen($tempPath, 'wb');

        if ($tempPath === false || $in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }

            if (is_resource($out)) {
                fclose($out);
            }

            $this->removeTemp($tempPath === false ? null : $tempPath);

            throw new ImportFileException(__('import.errors.file_unreadable'));
        }

        while (! feof($in)) {
            $block = fread($in, self::TRANSCODE_CHUNK);

            if ($block === false || $block === '') {
                break;
            }

            fwrite($out, $this->toUtf8($block));
        }

        fclose($in);
        fclose($out);

        return $tempPath;
    }

    private function removeTemp(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    private function unreadable(Throwable $e): ImportFileException
    {
        return new ImportFileException(__('import.errors.file_unreadable'), 0, $e);
    }

    private function cellToString(Cell $cell): ?string
    {
        if ($cell instanceof FormulaCell) {
            return $this->valueToString($cell->getComputedValue());
        }

        if ($cell instanceof EmptyCell || $cell instanceof ErrorCell) {
            return null;
        }

        if ($cell instanceof BooleanCell) {
            return $cell->getValue() ? '1' : '0';
        }

        if ($cell instanceof DateTimeCell) {
            return $cell->getValue()->format('Y-m-d');
        }

        if ($cell instanceof NumericCell) {
            return $this->valueToString($cell->getValue());
        }

        return $this->valueToString($cell->getValue());
    }

    private function valueToString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return $this->floatToString($value);
        }

        return $this->normalizeText(is_scalar($value) ? (string) $value : null);
    }

    /**
     * Float without binary artifacts: 12990.0 => "12990", 1290.5 => "1290.5", 0.30000000000000004 => "0.3".
     */
    private function floatToString(float $value): string
    {
        if (! is_finite($value)) {
            return '';
        }

        if ($value == floor($value) && abs($value) < 1.0e15) {
            return sprintf('%d', $value);
        }

        $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');

        return $text === '-0' || $text === '' ? '0' : $text;
    }

    /**
     * Trim, blank => null.
     */
    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) preg_replace('/^[\s\x{00A0}\x{202F}\x{2009}\x{FEFF}]+|[\s\x{00A0}\x{202F}\x{2009}\x{FEFF}]+$/u', '', $value));

        return $value === '' ? null : $value;
    }

    /**
     * Drop blank cells at the end of a row; an entirely blank row becomes [].
     *
     * @param  list<string|null>  $cells
     * @return list<string|null>
     */
    private function trimTrailingBlanks(array $cells): array
    {
        while ($cells !== [] && end($cells) === null) {
            array_pop($cells);
        }

        return $cells;
    }
}
