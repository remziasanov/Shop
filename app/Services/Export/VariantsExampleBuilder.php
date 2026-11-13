<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\ImportField;

/**
 * Generates `examples/import-variants.xlsx` (VR-16, VR-17, TZ appendix A): a T-shirt card with Цвет × Размер and its six
 * variant rows, plus a plain product, in the very columns of the import template; the sheet «Справка» tells the rules of
 * variant rows. Every article is fictional and uses its own prefix, so the file never collides with `shop:demo`.
 */
final class VariantsExampleBuilder
{
    public const FILE_NAME = 'import-variants.xlsx';

    public const CARD_SKU = 'TSH-100';

    /** @var list<string> option columns of the file, in order */
    public const OPTIONS = ['Цвет', 'Размер'];

    public function build(string $path): void
    {
        $book = new XlsxBook($path);

        try {
            $this->productsSheet($book);
            $this->helpSheet($book);
        } finally {
            $book->close();
        }
    }

    private function productsSheet(XlsxBook $book): void
    {
        $book->sheet(__('export.sheets.products'), [1 => 18, 2 => 18, 3 => 36, 4 => 28]);
        $book->header(CatalogExporter::productHeaders([], self::OPTIONS));

        $yes = __('export.yes');

        // The card: name, category and the base price that its variants inherit.
        $book->row($this->row(self::CARD_SKU, null, 'Футболка Basic', 'Одежда / Футболки', 1290.0, null, $yes, null, null, null));

        $stock = ['S' => 8, 'M' => 12, 'L' => 5];

        foreach (['Красный' => 'RED', 'Синий' => 'BLUE'] as $color => $colorCode) {
            foreach (['S', 'M', 'L'] as $position => $size) {
                $book->row($this->row(
                    self::CARD_SKU.'-'.$colorCode.'-'.$size,
                    self::CARD_SKU,
                    null,
                    null,
                    // Only the large size costs more; the others take the price of the card.
                    $size === 'L' ? 1390.0 : null,
                    $stock[$size] + ($color === 'Синий' ? 3 : 0),
                    $yes,
                    '46012345678'.str_pad((string) (($colorCode === 'RED' ? 0 : 3) + $position + 1), 2, '0', STR_PAD_LEFT),
                    $color,
                    $size,
                ));
            }
        }

        // A product without variants in the same file.
        $book->row($this->row('MUG-200', null, 'Кружка керамическая 350 мл', 'Дом / Посуда', 450.0, 25, $yes, null, null, null));
    }

    /**
     * One row in the order of the import columns followed by the option columns.
     *
     * @return list<string|int|float|null>
     */
    private function row(string $sku, ?string $parentSku, ?string $name, ?string $category, ?float $price, ?int $stock, string $active, ?string $barcode, ?string $color, ?string $size): array
    {
        $values = [
            ImportField::Sku->value => $sku,
            ImportField::Name->value => $name,
            ImportField::Category->value => $category,
            ImportField::Price->value => $price,
            ImportField::Stock->value => $stock,
            ImportField::Active->value => $active,
            ImportField::ParentSku->value => $parentSku,
            ImportField::Barcode->value => $barcode,
        ];

        $row = [];

        foreach (ImportField::cases() as $field) {
            $row[] = $values[$field->value] ?? null;
        }

        return [...$row, $color, $size];
    }

    private function helpSheet(XlsxBook $book): void
    {
        $book->sheet(__('export.sheets.help'), [1 => 120]);
        $book->header([__('export.variants_example.heading')]);

        foreach (__('export.variants_example.lines') as $line) {
            $book->row([$line]);
        }
    }
}
