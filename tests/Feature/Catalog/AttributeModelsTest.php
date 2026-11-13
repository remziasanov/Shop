<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Enums\FilterSource;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Settings\CatalogSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FL-01…FL-05, 20.4: tables, models, factories and the catalog settings.
 */
describe('models and factories', function (): void {
    it('creates attributes, values and product values with relations', function (): void {
        $attribute = Attribute::factory()->create(['name' => 'Материал']);
        $value = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => 'Хлопок']);
        $product = Product::factory()->create();
        $row = ProductAttributeValue::factory()->create([
            'product_id' => $product->id,
            'attribute_id' => $attribute->id,
            'attribute_value_id' => $value->id,
        ]);

        expect($attribute->type)->toBe(AttributeType::List)
            ->and($attribute->values)->toHaveCount(1)
            ->and($value->attribute->is($attribute))->toBeTrue()
            ->and($value->productValues)->toHaveCount(1)
            ->and($product->attributeValues->first()->is($row))->toBeTrue()
            ->and($row->value->value)->toBe('Хлопок')
            ->and($row->attribute->is($attribute))->toBeTrue()
            ->and($row->product->is($product))->toBeTrue()
            ->and($attribute->productValues)->toHaveCount(1);
    });

    it('builds number and boolean rows with the factory states', function (): void {
        $number = ProductAttributeValue::factory()->number('750.5000')->create();
        $boolean = ProductAttributeValue::factory()->boolean(true)->create();

        expect($number->attribute->type)->toBe(AttributeType::Number)
            ->and($number->attribute->unit)->toBe('Вт')
            ->and($number->attribute_value_id)->toBeNull()
            ->and($number->value_number)->toBe('750.5000')
            ->and($boolean->attribute->type)->toBe(AttributeType::Boolean)
            ->and($boolean->value_bool)->toBeTrue();
    });

    it('stores category filters and exposes them on the category in order', function (): void {
        $category = Category::factory()->create();
        $a = Attribute::factory()->create();
        $b = Attribute::factory()->create();
        CategoryFilter::factory()->create(['category_id' => $category->id, 'attribute_id' => $b->id, 'sort_order' => 20]);
        CategoryFilter::factory()->create(['category_id' => $category->id, 'attribute_id' => $a->id, 'sort_order' => 10, 'is_expanded' => true]);

        $filters = $category->filters;

        expect($filters)->toHaveCount(2)
            ->and($filters[0]->attribute_id)->toBe($a->id)
            ->and($filters[0]->is_expanded)->toBeTrue()
            ->and($filters[0]->source)->toBe(FilterSource::Attribute)
            ->and($filters[0]->category_id)->toBe($category->id)
            ->and($a->categoryFilters)->toHaveCount(1);
    });

    it('builds the signature of an attribute with its unit', function (): void {
        expect(Attribute::factory()->number('Вт')->make(['name' => 'Мощность'])->signature())->toBe('Мощность, Вт')
            ->and(Attribute::factory()->make(['name' => 'Цвет'])->signature())->toBe('Цвет');
    });
});

describe('constraints', function (): void {
    it('keeps attribute slugs unique and value slugs unique per attribute', function (): void {
        $a = Attribute::factory()->create(['slug' => 'color']);
        $b = Attribute::factory()->create();

        expect(fn () => Attribute::factory()->create(['slug' => 'color']))->toThrow(QueryException::class);

        AttributeValue::factory()->create(['attribute_id' => $a->id, 'slug' => 'red']);
        AttributeValue::factory()->create(['attribute_id' => $b->id, 'slug' => 'red']);

        expect(fn () => AttributeValue::factory()->create(['attribute_id' => $a->id, 'slug' => 'red']))->toThrow(QueryException::class);
    });

    it('restricts deleting a value that products use', function (): void {
        $row = ProductAttributeValue::factory()->create();

        expect(fn () => $row->value->delete())->toThrow(QueryException::class);
    });

    it('cascades attribute deletion to values, category filters and product values of numbers', function (): void {
        $number = ProductAttributeValue::factory()->number('5')->create();
        $attribute = $number->attribute;
        $filter = CategoryFilter::factory()->create(['attribute_id' => $attribute->id]);

        $attribute->delete();

        expect(ProductAttributeValue::query()->count())->toBe(0)
            ->and(CategoryFilter::query()->whereKey($filter->id)->exists())->toBeFalse();
    });

    it('cascades product deletion to its values', function (): void {
        $row = ProductAttributeValue::factory()->create();

        Product::query()->whereKey($row->product_id)->forceDelete();

        expect(ProductAttributeValue::query()->count())->toBe(0);
    });

    it('does not attach one list value twice to a product', function (): void {
        $row = ProductAttributeValue::factory()->create();

        expect(fn () => ProductAttributeValue::factory()->create([
            'product_id' => $row->product_id,
            'attribute_id' => $row->attribute_id,
            'attribute_value_id' => $row->attribute_value_id,
        ]))->toThrow(QueryException::class);
    });

    it('has the FL-12 indexes', function (): void {
        $columns = collect(Schema::getIndexes('product_attribute_values'))->map(fn (array $index): array => $index['columns'])->all();

        expect($columns)->toContain(['attribute_id', 'attribute_value_id', 'product_id'])
            ->toContain(['attribute_id', 'value_number', 'product_id'])
            ->toContain(['product_id', 'attribute_id']);

        expect(collect(Schema::getIndexes('category_filters'))->map(fn (array $i): array => $i['columns'])->all())->toContain(['category_id', 'sort_order'])
            ->and(collect(Schema::getIndexes('attributes'))->map(fn (array $i): array => $i['columns'])->all())->toContain(['is_filterable', 'sort_order']);
    });

    it('references option_types through option_type_id on MySQL (stage 11, VR-19)', function (): void {
        $foreign = collect(Schema::getForeignKeys('category_filters'))->map(fn (array $fk): array => $fk['columns'])->all();

        expect($foreign)->toContain(['category_id'])->toContain(['attribute_id']);

        // SQLite (tests only) cannot add a foreign key to an existing table: the migration creates it on MySQL only.
        if (DB::getDriverName() === 'sqlite') {
            expect($foreign)->not->toContain(['option_type_id']);
        } else {
            expect($foreign)->toContain(['option_type_id']);
        }
    });
});

describe('migrations', function (): void {
    it('rolls back and re-applies the four tables', function (): void {
        $migrations = collect(glob(database_path('migrations/2026_11_14_10000*_*.php')))->sort()->map(fn (string $file): object => require $file)->values();

        expect($migrations)->toHaveCount(4);

        foreach ($migrations->reverse() as $migration) {
            $migration->down();
        }

        foreach (['attributes', 'attribute_values', 'product_attribute_values', 'category_filters'] as $table) {
            expect(Schema::hasTable($table))->toBeFalse();
        }

        foreach ($migrations as $migration) {
            $migration->up();
        }

        foreach (['attributes', 'attribute_values', 'product_attribute_values', 'category_filters'] as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }
    });
});

describe('catalog settings', function (): void {
    it('has the defaults of 20.4', function (): void {
        $settings = app(CatalogSettings::class);

        expect($settings->filters_max_per_category)->toBe(9)
            ->and($settings->facets_cache_minutes)->toBe(10)
            ->and($settings->seo_single_value_min_products)->toBe(3)
            ->and($settings->create_attributes_on_import)->toBeTrue()
            ->and($settings->attributes_migrated_at)->toBeNull();
    });
});
