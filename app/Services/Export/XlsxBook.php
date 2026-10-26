<?php

declare(strict_types=1);

namespace App\Services\Export;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Thin streaming .xlsx writer on top of OpenSpout: several sheets, a bold header row, typed cells
 * (numbers stay numbers, text is never interpreted as a formula). Memory use does not depend on row count.
 */
final class XlsxBook
{
    /** Excel limit for the text of one cell. */
    private const MAX_TEXT = 32000;

    private readonly Writer $writer;

    private readonly Style $headerStyle;

    private bool $hasSheet = false;

    public function __construct(string $path)
    {
        $this->writer = new Writer;
        $this->writer->openToFile($path);
        $this->headerStyle = (new Style)->setFontBold();
    }

    /**
     * Start a sheet; the next rows go to it.
     *
     * @param  array<int, float>  $columnWidths  1-based column number => width
     */
    public function sheet(string $name, array $columnWidths = [], bool $freezeHeader = true): void
    {
        $sheet = $this->hasSheet
            ? $this->writer->addNewSheetAndMakeItCurrent()
            : $this->writer->getCurrentSheet();

        $this->hasSheet = true;
        $sheet->setName($name);

        foreach ($columnWidths as $column => $width) {
            $sheet->setColumnWidth($width, $column);
        }

        if ($freezeHeader) {
            $sheet->setSheetView((new SheetView)->setFreezeRow(2));
        }
    }

    /**
     * @param  list<string>  $headers
     */
    public function header(array $headers): void
    {
        $this->row($headers, $this->headerStyle);
    }

    /**
     * @param  list<string|int|float|bool|null>  $values  null and '' give an empty cell
     */
    public function row(array $values, ?Style $style = null): void
    {
        $cells = [];

        foreach ($values as $value) {
            $cells[] = match (true) {
                $value === null || $value === '' => Cell::fromValue(null, $style),
                is_string($value) => new StringCell(mb_substr($value, 0, self::MAX_TEXT), $style),
                default => Cell::fromValue($value, $style),
            };
        }

        $this->writer->addRow(new Row($cells));
    }

    public function close(): void
    {
        $this->writer->close();
    }
}
