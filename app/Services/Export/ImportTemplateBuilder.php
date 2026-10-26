<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\CategorySheetField;
use App\Enums\ImportField;
use App\Services\Import\HeaderNormalizer;

/**
 * Generates the import template (IM-01, TZ appendix A): sheet «Товары» with every column, one example row and
 * two characteristic columns, sheet «Категории» with an example 3-level path and sheet «Справка» that describes
 * every column and its allowed values.
 */
final class ImportTemplateBuilder
{
    /** Characteristic columns of the example. */
    public const EXAMPLE_ATTRIBUTES = ['Цвет', 'Материал'];

    public function build(string $path): void
    {
        $book = new XlsxBook($path);

        try {
            $this->productsSheet($book);
            $this->categoriesSheet($book);
            $this->helpSheet($book);
        } finally {
            $book->close();
        }
    }

    private function productsSheet(XlsxBook $book): void
    {
        $book->sheet(__('export.sheets.products'), [1 => 16, 2 => 36, 3 => 40, 12 => 40]);
        $book->header(CatalogExporter::productHeaders(self::EXAMPLE_ATTRIBUTES));
        $book->row([
            'ABC-123',
            'Смартфон Орион X1 128 ГБ',
            'Электроника / Телефоны / Смартфоны',
            12990.0,
            9500.0,
            14990.0,
            15,
            'шт',
            'Acme',
            'Компактный смартфон с ярким экраном.',
            '<p>Подробное описание товара. Можно использовать простой HTML.</p>',
            'a1.jpg; a2.jpg',
            __('export.yes'),
            __('export.yes'),
            __('export.no'),
            'корзина',
            __('export.no'),
            10,
            'Смартфон Орион X1 128 ГБ — купить',
            'Смартфон Орион X1 с экраном 6,1 дюйма и памятью 128 ГБ.',
            'Чёрный',
            'Стекло, алюминий',
        ]);
    }

    private function categoriesSheet(XlsxBook $book): void
    {
        $book->sheet(CategorySheetField::SHEET_NAME, [1 => 40, 2 => 40]);
        $book->header(CatalogExporter::categoryHeaders());

        $yes = __('export.yes');

        $book->row(['Электроника', 'Смартфоны, наушники и аксессуары.', 10, $yes, null, null, 'Электроника', null]);
        $book->row(['Электроника / Телефоны', null, 10, $yes, null, null, null, null]);
        $book->row(['Электроника / Телефоны / Смартфоны', 'Смартфоны на любой вкус и бюджет.', 10, $yes, 'корзина и резерв', 'smartphones.jpg', 'Смартфоны — каталог', 'Купить смартфон с доставкой.']);
    }

    private function helpSheet(XlsxBook $book): void
    {
        $book->sheet(__('export.sheets.help'), [1 => 24, 2 => 20, 3 => 44, 4 => 90]);
        $book->header(__('export.template.help_columns'));

        foreach (ImportField::cases() as $field) {
            $book->row([
                $field->label(),
                $this->requirement($field),
                implode(', ', $this->synonyms($field)),
                __('export.template.format.'.$field->value),
            ]);
        }

        $book->row([
            __('export.template.attribute_name'),
            __('export.template.required.no'),
            __('export.template.attribute_synonyms'),
            __('export.template.attribute_format'),
        ]);

        $book->row([]);
        $book->row([__('export.template.categories_heading'), null, null, __('export.template.categories_note')]);

        foreach (CategorySheetField::cases() as $field) {
            $book->row([
                $field->label(),
                $field === CategorySheetField::Path ? __('export.template.required.yes') : __('export.template.required.no'),
                implode(', ', $this->categorySynonyms($field)),
                null,
            ]);
        }

        $book->row([]);
        $book->row([__('export.template.general_heading')]);

        foreach (__('export.template.general') as $line) {
            $book->row([null, null, null, $line]);
        }
    }

    private function requirement(ImportField $field): string
    {
        return match ($field) {
            ImportField::Sku => __('export.template.required.yes'),
            ImportField::Name, ImportField::Category, ImportField::Price => __('export.template.required.new'),
            default => __('export.template.required.no'),
        };
    }

    /**
     * Synonyms to show: without the canonical header itself and without the technical key unless it is a word users type.
     *
     * @return list<string>
     */
    private function synonyms(ImportField $field): array
    {
        return $this->distinct($field->synonyms(), $field->label());
    }

    /**
     * @return list<string>
     */
    private function categorySynonyms(CategorySheetField $field): array
    {
        return $this->distinct($field->synonyms(), $field->label());
    }

    /**
     * @param  list<string>  $synonyms
     * @return list<string>
     */
    private function distinct(array $synonyms, string $label): array
    {
        $seen = [HeaderNormalizer::normalize($label) => true];
        $result = [];

        foreach ($synonyms as $synonym) {
            $key = HeaderNormalizer::normalize($synonym);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = rtrim($synonym, '*');
        }

        return $result;
    }
}
