<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Exceptions\AttributeException;
use App\Jobs\RebuildAttributeCache;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\Catalog\CatalogVersion;
use Illuminate\Support\Facades\Queue;

/**
 * FL-01…FL-03, FL-18, FL-20: the only writer of product characteristic values.
 */
describe('list values', function (): void {
    it('creates dictionary values from free text with unique slugs', function (): void {
        $material = Attribute::factory()->create(['name' => 'Материал']);
        $product = Product::factory()->create(['attributes' => null]);

        attrService()->setProductValues($product, [['attribute_id' => $material->id, 'values' => ['Хлопок', 'Лён']]]);

        $values = AttributeValue::query()->where('attribute_id', $material->id)->orderBy('id')->get();

        expect($values->pluck('value')->all())->toBe(['Хлопок', 'Лён'])
            ->and($values->pluck('slug')->all())->toBe(['hlopok', 'lyon'])
            ->and(attrValuesOf($product, $material))->toHaveCount(2);
    });

    it('reuses a value regardless of case, spaces and ё and numbers colliding slugs', function (): void {
        $attribute = Attribute::factory()->create();
        $first = Product::factory()->create(['attributes' => null]);
        $second = Product::factory()->create(['attributes' => null]);

        attrService()->setProductValues($first, [['attribute_id' => $attribute->id, 'values' => ['Зелёный', 'a b']]]);
        attrService()->setProductValues($second, [['attribute_id' => $attribute->id, 'values' => [' зеленый ', 'a-b']]]);

        $values = AttributeValue::query()->where('attribute_id', $attribute->id)->orderBy('id')->get();

        expect($values->pluck('value')->all())->toBe(['Зелёный', 'a b', 'a-b'])
            ->and($values->pluck('slug')->all())->toBe(['zelyonyy', 'a-b', 'a-b-2']);
    });

    it('accepts ids of existing values and rejects foreign ones', function (): void {
        $attribute = Attribute::factory()->create();
        $other = Attribute::factory()->create();
        $mine = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);
        $foreign = AttributeValue::factory()->create(['attribute_id' => $other->id]);
        $product = Product::factory()->create(['attributes' => null]);

        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => [$mine->id, $mine->id]]]);
        expect(attrValuesOf($product, $attribute))->toHaveCount(1);

        expect(fn () => attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => [$foreign->id]]]))
            ->toThrow(AttributeException::class);
    });

    it('replaces the values of the pair and removes the characteristic on empty values', function (): void {
        $attribute = Attribute::factory()->create();
        $product = Product::factory()->create(['attributes' => null]);

        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => ['A', 'B']]]);
        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => 'C']]);

        $rows = attrValuesOf($product, $attribute);
        expect($rows)->toHaveCount(1)->and($rows[0]->value->value)->toBe('C');

        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => ['', null]]]);
        expect(attrValuesOf($product, $attribute))->toBe([]);
    });

    it('rejects a value longer than 255 characters', function (): void {
        $attribute = Attribute::factory()->create();
        $product = Product::factory()->create();

        expect(fn () => attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => [str_repeat('я', 256)]]]))
            ->toThrow(AttributeException::class);
    });
});

describe('number and boolean values', function (): void {
    it('stores one decimal number rounded to four places and accepts the unit suffix', function (): void {
        $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $product = Product::factory()->create(['attributes' => null]);

        foreach ([['1 200,5', '1200.5000'], ['500 Вт', '500.0000'], [750, '750.0000'], [2.5, '2.5000'], ['0.12345', '0.1235'], ['-3', '-3.0000'], ['-0.00001', '0.0000']] as [$input, $expected]) {
            attrService()->setProductValues($product, [['attribute_id' => $power->id, 'values' => $input]]);
            $rows = attrValuesOf($product, $power);

            expect($rows)->toHaveCount(1)->and($rows[0]->value_number)->toBe($expected);
        }
    });

    it('rejects text, a foreign unit, several values and out-of-range numbers', function (): void {
        $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $product = Product::factory()->create();

        foreach (['много', '5 кг', '12345678901', '1e5'] as $bad) {
            expect(fn () => attrService()->setProductValues($product, [['attribute_id' => $power->id, 'values' => $bad]]))->toThrow(AttributeException::class);
        }

        expect(fn () => attrService()->setProductValues($product, [['attribute_id' => $power->id, 'values' => [1, 2]]]))
            ->toThrow(AttributeException::class);

        expect(ProductAttributeValue::query()->count())->toBe(0);
    });

    it('stores booleans from да/нет/yes/no/1/0/bool and rejects other text', function (): void {
        $waterproof = Attribute::factory()->boolean()->create(['name' => 'Водонепроницаемый']);
        $product = Product::factory()->create(['attributes' => null]);

        foreach ([['да', true], ['Нет', false], ['yes', true], ['no', false], [1, true], [0, false], [true, true], [false, false]] as [$input, $expected]) {
            attrService()->setProductValues($product, [['attribute_id' => $waterproof->id, 'values' => $input]]);
            $rows = attrValuesOf($product, $waterproof);

            expect($rows)->toHaveCount(1)->and($rows[0]->value_bool)->toBe($expected);
        }

        expect(fn () => attrService()->setProductValues($product, [['attribute_id' => $waterproof->id, 'values' => 'возможно']]))
            ->toThrow(AttributeException::class)
            ->and(fn () => attrService()->setProductValues($product, [['attribute_id' => $waterproof->id, 'values' => ['да', 'нет']]]))
            ->toThrow(AttributeException::class);
    });
});

describe('product writes', function (): void {
    it('addresses attributes by id or by name and refuses unknown ones', function (): void {
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $product = Product::factory()->create(['attributes' => null]);

        attrService()->setProductValues($product, [['name' => 'цвет', 'values' => 'Красный']]);
        expect(attrValuesOf($product, $color))->toHaveCount(1);

        expect(fn () => attrService()->setProductValues($product, [['name' => 'Нет такой', 'values' => 'x']]))->toThrow(AttributeException::class)
            ->and(fn () => attrService()->setProductValues($product, [['attribute_id' => 99999, 'values' => 'x']]))->toThrow(AttributeException::class);
    });

    it('replaces everything by default and only the listed attributes without replaceAll', function (): void {
        $a = Attribute::factory()->create();
        $b = Attribute::factory()->create();
        $product = Product::factory()->create(['attributes' => null]);

        attrService()->setProductValues($product, [['attribute_id' => $a->id, 'values' => 'x'], ['attribute_id' => $b->id, 'values' => 'y']]);
        attrService()->setProductValues($product, [['attribute_id' => $a->id, 'values' => 'z']], replaceAll: false);

        expect(attrValuesOf($product, $a))->toHaveCount(1)->and(attrValuesOf($product, $b))->toHaveCount(1);

        attrService()->setProductValues($product, [['attribute_id' => $a->id, 'values' => 'z']]);

        expect(attrValuesOf($product, $b))->toBe([]);
    });

    it('writes nothing when one item is invalid and keeps the dictionary clean', function (): void {
        $list = Attribute::factory()->create();
        $number = Attribute::factory()->number()->create();
        $product = Product::factory()->create(['attributes' => null]);

        expect(fn () => attrService()->setProductValues($product, [
            ['attribute_id' => $list->id, 'values' => 'новое'],
            ['attribute_id' => $number->id, 'values' => 'abc'],
        ]))->toThrow(AttributeException::class);

        expect(ProductAttributeValue::query()->count())->toBe(0)
            ->and(AttributeValue::query()->count())->toBe(0);
    });

    it('refreshes the JSON cache of the product and its model', function (): void {
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $product = Product::factory()->create(['attributes' => null]);

        attrService()->setProductValues($product, [['attribute_id' => $color->id, 'values' => ['Красный', 'Синий']]]);

        expect($product->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный, Синий']])
            ->and($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный, Синий']]);
    });

    it('bumps the catalog version', function (): void {
        $color = Attribute::factory()->create();
        $product = Product::factory()->create();
        $before = CatalogVersion::current();

        attrService()->setProductValues($product, [['attribute_id' => $color->id, 'values' => 'x']]);

        expect(CatalogVersion::current())->not->toBe($before);
    });
});

describe('bulk actions', function (): void {
    it('sets and removes one characteristic for many products in chunks', function (): void {
        attrMigrated();
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $products = Product::factory()->count(3)->create(['attributes' => null]);
        $ids = $products->pluck('id')->all();

        $done = attrService()->setAttributeForProducts($color, ['Красный', 'Синий'], [...$ids, 99999]);

        expect($done)->toBe(3)
            ->and(ProductAttributeValue::query()->where('attribute_id', $color->id)->count())->toBe(6)
            ->and($products[0]->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный, Синий']]);

        // Replacing: previous values of those products go.
        attrService()->setAttributeForProducts($color, 'Зелёный', [$ids[0]]);
        expect(attrValuesOf($products[0], $color))->toHaveCount(1);

        $removed = attrService()->removeAttributeFromProducts($color, $ids);

        expect($removed)->toBe(3)
            ->and(ProductAttributeValue::query()->count())->toBe(0)
            ->and($products[1]->fresh()->specs())->toBe([]);
    });

    it('uses chunks of at most 500 products', function (): void {
        $color = Attribute::factory()->create();
        $ids = Product::factory()->count(3)->create()->pluck('id')->all();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        attrService()->removeAttributeFromProducts($color, $ids);

        // One chunk: a single lookup, no write for products that had no value.
        expect($queries)->toBeLessThan(5);
    });

    it('validates before touching any product', function (): void {
        $number = Attribute::factory()->number()->create();
        $product = Product::factory()->create();

        expect(fn () => attrService()->setAttributeForProducts($number, 'abc', [$product->id]))->toThrow(AttributeException::class);
    });
});

describe('dictionary values', function (): void {
    it('merges values: products move, duplicates collapse, the source is deleted', function (): void {
        attrMigrated();
        $attribute = Attribute::factory()->create(['name' => 'Материал']);
        $from = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Хлопок 100%']);
        $to = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Хлопок']);
        $onlyFrom = Product::factory()->create(['attributes' => null]);
        $both = Product::factory()->create(['attributes' => null]);
        $untouched = Product::factory()->create(['attributes' => null]);
        attrService()->setProductValues($onlyFrom, [['attribute_id' => $attribute->id, 'values' => [$from->id]]]);
        attrService()->setProductValues($both, [['attribute_id' => $attribute->id, 'values' => [$from->id, $to->id]]]);
        attrService()->setProductValues($untouched, [['attribute_id' => $attribute->id, 'values' => [$to->id]]]);

        $affected = attrService()->mergeValues($from, $to);

        expect($affected)->toBe(2)
            ->and(AttributeValue::query()->whereKey($from->id)->exists())->toBeFalse()
            ->and(ProductAttributeValue::query()->where('attribute_value_id', $to->id)->count())->toBe(3)
            ->and(attrValuesOf($both, $attribute))->toHaveCount(1)
            ->and($onlyFrom->fresh()->specs())->toBe([['name' => 'Материал', 'value' => 'Хлопок']]);
    });

    it('refuses to merge a value with itself or across attributes', function (): void {
        $a = AttributeValue::factory()->create();
        $b = AttributeValue::factory()->create();

        expect(fn () => attrService()->mergeValues($a, $a))->toThrow(AttributeException::class)
            ->and(fn () => attrService()->mergeValues($a, $b))->toThrow(AttributeException::class);
    });

    it('refuses to delete a used value with the product count and deletes an unused one', function (): void {
        $row = ProductAttributeValue::factory()->create();
        $unused = AttributeValue::factory()->create(['attribute_id' => $row->attribute_id]);

        try {
            attrService()->deleteValue($row->value);
            $this->fail('Expected an exception.');
        } catch (AttributeException $e) {
            expect($e->count)->toBe(1)->and($e->getMessage())->toContain('1');
        }

        attrService()->deleteValue($unused);

        expect(AttributeValue::query()->whereKey($unused->id)->exists())->toBeFalse();
    });

    it('renames a value keeping the slug, refuses a clash and rebuilds the JSON of its products', function (): void {
        Queue::fake();
        $attribute = Attribute::factory()->create();
        $value = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Крассный', 'slug' => 'red']);
        AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Синий']);
        ProductAttributeValue::factory()->create(['attribute_id' => $attribute->id, 'attribute_value_id' => $value->id]);

        $value = AttributeValue::query()->find($value->id);
        attrService()->renameValue($value, 'Красный');

        expect($value->fresh()->value)->toBe('Красный')->and($value->fresh()->slug)->toBe('red');
        Queue::assertPushed(RebuildAttributeCache::class);

        expect(fn () => attrService()->renameValue($value, ' синий '))->toThrow(AttributeException::class);
    });

    it('reorders values', function (): void {
        $attribute = Attribute::factory()->create();
        $values = AttributeValue::factory()->count(3)->create(['attribute_id' => $attribute->id]);

        attrService()->reorderValues($attribute, [$values[2]->id, $values[0]->id]);

        expect($attribute->values()->pluck('id')->all())->toBe([$values[2]->id, $values[0]->id, $values[1]->id]);
    });
});

describe('attribute rules', function (): void {
    it('creates an attribute with a unique slug and normalizes the unit', function (): void {
        $first = attrService()->createAttribute(['name' => 'Мощность', 'type' => 'number', 'unit' => ' Вт ']);
        $second = attrService()->createAttribute(['name' => 'Мощность', 'type' => AttributeType::Number, 'unit' => 'кВт']);

        expect($first->slug)->toBe('moschnost')
            ->and($second->slug)->toBe($first->slug.'-2')
            ->and($first->unit)->toBe('Вт')
            ->and($first->is_filterable)->toBeFalse();

        expect(fn () => attrService()->createAttribute(['name' => '  ']))->toThrow(AttributeException::class)
            ->and(fn () => attrService()->createAttribute(['name' => 'Цвет', 'type' => 'list', 'unit' => 'см']))->toThrow(AttributeException::class);
    });

    it('refuses to change the type while products have values (FL-01)', function (): void {
        $row = ProductAttributeValue::factory()->create();
        $attribute = Attribute::query()->find($row->attribute_id);

        try {
            attrService()->updateAttribute($attribute, ['type' => 'number']);
            $this->fail('Expected an exception.');
        } catch (AttributeException $e) {
            expect($e->count)->toBe(1);
        }

        expect($attribute->fresh()->type)->toBe(AttributeType::List);

        // Allowed once the values are gone.
        attrService()->removeAttributeFromProducts($attribute, [$row->product_id]);
        attrService()->updateAttribute($attribute, ['type' => 'number', 'unit' => 'кг']);

        expect($attribute->fresh()->type)->toBe(AttributeType::Number)->and($attribute->fresh()->unit)->toBe('кг');
    });

    it('rebuilds the JSON of the products when the name changes', function (): void {
        $row = ProductAttributeValue::factory()->create();
        $attribute = Attribute::query()->find($row->attribute_id);
        attrService()->setProductValues($row->product_id, [['attribute_id' => $attribute->id, 'values' => [$row->attribute_value_id]]]);

        attrService()->updateAttribute($attribute, ['name' => 'Новое имя']);

        expect(Product::query()->find($row->product_id)->specs()[0]['name'])->toBe('Новое имя');
    });

    it('refuses to delete a used attribute, reporting the product count', function (): void {
        $row = ProductAttributeValue::factory()->create();
        $attribute = Attribute::query()->find($row->attribute_id);

        try {
            attrService()->deleteAttribute($attribute);
            $this->fail('Expected an exception.');
        } catch (AttributeException $e) {
            expect($e->count)->toBe(1);
        }

        expect(Attribute::query()->whereKey($attribute->id)->exists())->toBeTrue();
    });

    it('detaches and deletes: values go, the count is returned and the JSON rebuild is queued', function (): void {
        attrMigrated();
        Queue::fake();
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $size = Attribute::factory()->create(['name' => 'Размер']);
        $product = Product::factory()->create(['attributes' => null]);
        attrService()->setProductValues($product, [['attribute_id' => $color->id, 'values' => 'Красный'], ['attribute_id' => $size->id, 'values' => 'L']]);

        $count = attrService()->deleteAttribute($color, detach: true);

        expect($count)->toBe(1)
            ->and(Attribute::query()->whereKey($color->id)->exists())->toBeFalse()
            ->and(AttributeValue::query()->where('attribute_id', $color->id)->count())->toBe(0)
            ->and(ProductAttributeValue::query()->where('attribute_id', $color->id)->count())->toBe(0);

        Queue::assertPushed(RebuildAttributeCache::class, fn (RebuildAttributeCache $job): bool => $job->productIds === [$product->id]);
    });

    it('deletes an unused attribute without queueing anything', function (): void {
        Queue::fake();
        $attribute = Attribute::factory()->create();

        expect(attrService()->deleteAttribute($attribute))->toBe(0);
        Queue::assertNothingPushed();
    });

    it('rebuilds the JSON through the job when it runs', function (): void {
        attrMigrated();
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $product = Product::factory()->create(['attributes' => null]);
        attrService()->setProductValues($product, [['attribute_id' => $color->id, 'values' => 'Красный']]);

        // Sync queue driver in tests: the job runs at dispatch.
        attrService()->deleteAttribute($color, detach: true);

        expect($product->fresh()->specs())->toBe([]);
    });
});

describe('copying values', function (): void {
    it('copies all rows exactly and rebuilds the JSON of the copy', function (): void {
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $source = Product::factory()->create(['attributes' => null]);
        $target = Product::factory()->create(['attributes' => null]);
        attrService()->setProductValues($source, [['attribute_id' => $color->id, 'values' => ['Красный']], ['attribute_id' => $power->id, 'values' => '12.5']]);

        $copied = attrService()->copyProductValues($source, $target);

        expect($copied)->toBe(2)
            ->and(attrValuesOf($target, $power)[0]->value_number)->toBe('12.5000')
            ->and($target->specs())->toBe($source->specs());
    });
});
