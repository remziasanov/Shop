<?php

declare(strict_types=1);

namespace Tests\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Builds import files for tests.
 */
final class ImportFiles
{
    public static function tempPath(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'imptest');
        unlink($base);

        return $base.'.'.$extension;
    }

    /**
     * A small JPEG file on disk (for the image processor).
     */
    public static function tempJpeg(int $width = 120, int $height = 80, int $red = 200): string
    {
        $path = self::tempPath('jpg');
        file_put_contents($path, ImportHarness::jpeg($width, $height, $red));

        return $path;
    }

    /**
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function csv(array $rows, string $delimiter = ';', string $encoding = 'UTF-8', bool $bom = false): string
    {
        $path = self::tempPath('csv');
        $handle = fopen($path, 'wb');

        foreach ($rows as $row) {
            $line = implode($delimiter, array_map(function ($value) use ($delimiter): string {
                $value = (string) $value;

                return str_contains($value, $delimiter) || str_contains($value, '"') || str_contains($value, "\n")
                    ? '"'.str_replace('"', '""', $value).'"'
                    : $value;
            }, $row))."\r\n";

            fwrite($handle, $encoding === 'UTF-8' ? $line : mb_convert_encoding($line, $encoding, 'UTF-8'));
        }

        fclose($handle);

        if ($bom) {
            file_put_contents($path, "\xEF\xBB\xBF".file_get_contents($path));
        }

        return $path;
    }

    /**
     * @param  array<string, list<list<string|int|float|bool|null>>>  $sheets  sheet name => rows
     */
    public static function xlsx(array $sheets): string
    {
        $path = self::tempPath('xlsx');
        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName((string) $name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(array_filter($row, fn ($v) => $v instanceof Cell) === []
                    ? Row::fromValues($row)
                    : new Row(array_map(fn ($v) => $v instanceof Cell ? $v : Cell::fromValue($v), $row)));
            }
        }

        $writer->close();

        return $path;
    }

    public static function dateCell(string $date): Cell
    {
        return Cell::fromValue(new \DateTimeImmutable($date), (new Style)->setFormat('yyyy-mm-dd'));
    }

    /**
     * A product CSV with $count rows; every 50th row has a bad price, every 100th repeats the SKU of row 1.
     *
     * @param  list<string>  $existingSkus  SKUs written first (as rows 2..)
     */
    public static function productsCsv(int $count, array $existingSkus = []): string
    {
        $rows = [['Артикул', 'Название', 'Категория', 'Цена', 'Остаток']];

        for ($i = 1; $i <= $count; $i++) {
            $sku = $existingSkus[$i - 1] ?? 'SKU-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT);

            if ($i % 100 === 0) {
                $sku = $existingSkus[0] ?? 'SKU-000001';
            }

            $rows[] = [$sku, 'Товар '.$i, 'Мебель / Столы', $i % 50 === 0 ? 'abc' : (string) (1000 + $i).',50', (string) ($i % 20)];
        }

        return self::csv($rows);
    }
}
