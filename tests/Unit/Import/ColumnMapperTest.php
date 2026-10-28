<?php

declare(strict_types=1);

use App\Enums\CategorySheetField;
use App\Enums\ImportField;
use App\Services\Import\ColumnMapper;
use App\Services\Import\HeaderNormalizer;

/**
 * Synonyms exactly as listed in TZ appendix A.
 */
function appendixSynonyms(): array
{
    return [
        'sku' => ['Артикул', 'sku', 'код', 'код товара', 'артикул товара'],
        'name' => ['Название', 'наименование', 'name', 'товар'],
        'category' => ['Категория', 'раздел', 'путь категории', 'category'],
        'price' => ['Цена', 'базовая цена', 'розничная цена', 'price'],
        'cost_price' => ['Закупочная цена', 'закупка', 'себестоимость', 'cost'],
        'old_price' => ['Старая цена', 'цена до скидки', 'old price'],
        'stock' => ['Остаток', 'количество', 'кол-во', 'наличие', 'stock', 'qty'],
        'unit' => ['Единица', 'ед.', 'ед. изм.', 'unit'],
        'brand' => ['Бренд', 'производитель', 'марка', 'brand'],
        'short_description' => ['Краткое описание', 'анонс', 'short description'],
        'description' => ['Описание', 'полное описание', 'description'],
        'photos' => ['Фото', 'изображения', 'картинки', 'photo', 'images'],
        'active' => ['Активен', 'показывать', 'опубликован', 'active'],
        'featured' => ['Хит', 'хит продаж', 'featured'],
        'new' => ['Новинка', 'new'],
        'sales_mode' => ['Режим продажи', 'режим', 'sales mode'],
        'price_on_request' => ['Цена по запросу', 'price on request'],
        'sort' => ['Порядок', 'сортировка', 'sort'],
        'meta_title' => ['Meta title', 'seo title'],
        'meta_description' => ['Meta description', 'seo description'],
        'age' => ['Возраст', 'возрастное ограничение', 'age'],
    ];
}

dataset('appendix_a_synonyms', fn () => array_map(null, array_keys(appendixSynonyms()), array_values(appendixSynonyms())));

it('maps every appendix A synonym', function (string $field, array $headers) {
    $mapper = new ColumnMapper;

    foreach ($headers as $header) {
        expect($mapper->autoMap([$header]))->toBe([0 => $field], "header \"{$header}\"");
    }
})->with('appendix_a_synonyms');

it('covers every canonical field in the appendix A table', function () {
    expect(array_keys(appendixSynonyms()))->toEqualCanonicalizing(array_map(fn (ImportField $f): string => $f->value, ImportField::cases()));
});

it('ignores case, spaces, punctuation and yo', function () {
    $mapper = new ColumnMapper;

    expect($mapper->autoMap(['  АРТИКУЛ ', 'Кол-ВО', 'Кол во', 'Ц Е Н А', 'Meta_Title', 'META-DESCRIPTION', 'ЕД. ИЗМ', 'Закупочная  цена*']))
        ->toBe([0 => 'sku', 1 => 'stock', 2 => null, 3 => 'price', 4 => 'meta_title', 5 => 'meta_description', 6 => 'unit', 7 => 'cost_price']);
});

it('normalizes headers', function () {
    expect(HeaderNormalizer::normalize(' Ёлка-Кол. №5 '))->toBe('елкакол5');
});

it('turns characteristic columns into attributes', function (string $header, ?string $expected) {
    expect((new ColumnMapper)->autoMap([$header]))->toBe([0 => $expected]);
})->with([
    'cyrillic Х' => ['Х: Цвет', 'attr:Цвет'],
    'lowercase х' => ['х:Материал корпуса', 'attr:Материал корпуса'],
    'latin X typo' => ['X: Размер', 'attr:Размер'],
    'latin x typo' => ['x : Размер', 'attr:Размер'],
    'long form' => ['Характеристика: Вес, кг', 'attr:Вес, кг'],
    'long form upper' => ['ХАРАКТЕРИСТИКА: Страна', 'attr:Страна'],
    'empty name' => ['Х:', null],
]);

it('ignores unknown columns', function () {
    expect((new ColumnMapper)->autoMap(['Артикул', 'Комментарий менеджера', '', null, 'Цена']))
        ->toBe([0 => 'sku', 1 => null, 2 => null, 3 => null, 4 => 'price']);
});

it('keeps the first of two columns mapped to the same field', function () {
    $result = (new ColumnMapper)->autoMapWithWarnings(['Артикул', 'Цена', 'Розничная цена', 'Х: Цвет', 'X: Цвет']);

    expect($result->mapping)->toBe([0 => 'sku', 1 => 'price', 2 => null, 3 => 'attr:Цвет', 4 => null])
        ->and($result->duplicates)->toHaveCount(2)
        ->and($result->duplicates[0])->toMatchArray(['column' => 2, 'field' => 'price', 'first' => 1])
        ->and($result->warnings(['Артикул', 'Цена', 'Розничная цена']))->toHaveCount(2)
        ->and($result->warnings(['Артикул', 'Цена', 'Розничная цена'])[0])->toContain('Розничная цена')->toContain('Цена');
});

it('maps the categories sheet columns', function () {
    expect((new ColumnMapper)->autoMapCategories(['Путь*', 'Описание', 'Порядок', 'Активна', 'Режим продажи', 'Фото', 'Meta title', 'Meta description', 'Лишнее']))
        ->toBe([0 => 'path', 1 => 'description', 2 => 'sort', 3 => 'active', 4 => 'sales_mode', 5 => 'photo', 6 => 'meta_title', 7 => 'meta_description', 8 => null]);

    expect(CategorySheetField::cases())->toHaveCount(9);
});

it('maps a raw row through a mapping', function () {
    $mapper = new ColumnMapper;
    $mapped = $mapper->mapRow(['A1', 'Стол', '', 'чёрный', null, 'x'], [0 => 'sku', 1 => 'name', 2 => 'price', 3 => 'attr:Цвет', 4 => 'attr:Вес', 5 => null]);

    expect($mapped->values)->toBe(['sku' => 'A1', 'name' => 'Стол', 'price' => null])
        ->and($mapped->attributes)->toBe([['name' => 'Цвет', 'value' => 'чёрный']])
        ->and($mapped->has('price'))->toBeTrue()
        ->and($mapped->has('brand'))->toBeFalse();
});
