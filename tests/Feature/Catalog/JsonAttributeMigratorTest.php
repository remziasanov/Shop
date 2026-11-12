<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Jobs\MigrateAttributesFromJson;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Services\Catalog\AttributeJsonBackup;
use App\Services\Catalog\AttributeMigrationState;
use App\Services\Catalog\AttributeService;
use App\Services\Catalog\JsonAttributeMigrator;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

/**
 * FL-04: migration of the v1 JSON characteristics into the tables.
 */
function attrMigrator(): JsonAttributeMigrator
{
    return app(JsonAttributeMigrator::class);
}

/**
 * @param  list<array{0: string, 1: string}>  $pairs
 */
function attrJsonProduct(array $pairs): Product
{
    return Product::factory()->create(['attributes' => array_map(fn (array $p): array => ['name' => $p[0], 'value' => $p[1]], $pairs)]);
}

describe('dry run', function (): void {
    it('reports attributes, values, merged names, disputed types and non-filterable ones without writing', function (): void {
        // "Цвет" / "цвет" / "ЦВЕТ " are one attribute (case and spaces).
        attrJsonProduct([['Цвет', 'Красный'], ['Мощность, Вт', '500'], ['Водонепроницаемый', 'да'], ['Размер, см', '10']]);
        attrJsonProduct([['цвет', 'красный'], ['Мощность, Вт', '750'], ['Водонепроницаемый', 'нет'], ['Размер, см', 'большой']]);
        attrJsonProduct([['ЦВЕТ ', 'Синий'], ['Мощность, Вт', '900'], ['Размер, см', '12'], ['Описание', str_repeat('я', 150)]]);

        $report = attrMigrator()->dryRun();
        $created = collect($report->toCreate())->keyBy('name');

        expect($report->products)->toBe(3)
            ->and($created->keys()->sort()->values()->all())->toBe(collect(['Цвет', 'Мощность', 'Водонепроницаемый', 'Размер, см', 'Описание'])->sort()->values()->all())
            ->and($created['Цвет']->inference->type)->toBe(AttributeType::List)
            ->and($created['Цвет']->newValues)->toBe(2)
            ->and($created['Мощность']->inference->type)->toBe(AttributeType::Number)
            ->and($created['Мощность']->unit)->toBe('Вт')
            ->and($created['Водонепроницаемый']->inference->type)->toBe(AttributeType::Boolean)
            ->and(Attribute::query()->count())->toBe(0)
            ->and(ProductAttributeValue::query()->count())->toBe(0);

        // «Размер, см»: 2 of 3 values are numbers -> list, the full header stays the name, the type is disputed.
        expect($created['Размер, см']->inference->type)->toBe(AttributeType::List)
            ->and(collect($report->disputed())->pluck('name')->all())->toContain('Размер, см');

        // «Описание»: values longer than 100 characters -> not filterable.
        expect(collect($report->nonFilterable())->pluck('name')->all())->toBe(['Описание']);

        // Merged spellings of "Цвет".
        $merged = collect($report->mergedNames())->first(fn (array $names): bool => in_array('Цвет', $names, true));
        expect($merged)->toContain('Цвет', 'цвет', 'ЦВЕТ');

        expect($report->valuesToCreate())->toBeGreaterThan(0)
            ->and($report->toArray())->toHaveKeys(['products', 'to_create', 'existing', 'values_to_create', 'merged_names', 'disputed', 'non_filterable']);
    });

    it('uses existing attributes by the FL-16 rules and reports them as merged names', function (): void {
        Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        attrJsonProduct([['Мощность', '500'], ['Мощность, Вт', '600']]);

        $report = attrMigrator()->dryRun();

        expect($report->toCreate())->toBe([])
            ->and($report->existing())->toHaveCount(2)
            ->and($report->mergedNames())->toHaveCount(1);
    });

    it('prints through the command', function (): void {
        attrJsonProduct([['Цвет', 'Красный'], ['Мощность, Вт', '500']]);

        $this->artisan('shop:attributes-migrate --dry-run')
            ->expectsOutputToContain('Холостой прогон')
            ->expectsOutputToContain('Товаров с характеристиками: 1')
            ->assertSuccessful();

        expect(Attribute::query()->count())->toBe(0);
    });
});

describe('migration', function (): void {
    it('creates typed attributes with units and filters and writes normalized values', function (): void {
        $product = attrJsonProduct([
            ['Материал', 'Хлопок; Лён'],
            ['Мощность, Вт', '1 200,5'],
            ['Водонепроницаемый', 'Да'],
        ]);
        attrJsonProduct([['Материал', 'хлопок'], ['Мощность, Вт', '750'], ['Водонепроницаемый', 'нет']]);

        expect(attrMigrator()->run())->toBeTrue();

        $material = Attribute::query()->where('name', 'Материал')->firstOrFail();
        $power = Attribute::query()->where('name', 'Мощность')->firstOrFail();
        $dry = Attribute::query()->where('name', 'Водонепроницаемый')->firstOrFail();

        expect($material->type)->toBe(AttributeType::List)->and($material->is_filterable)->toBeTrue()
            ->and($power->type)->toBe(AttributeType::Number)->and($power->unit)->toBe('Вт')->and($power->is_filterable)->toBeTrue()
            ->and($dry->type)->toBe(AttributeType::Boolean)
            ->and(AttributeValue::query()->where('attribute_id', $material->id)->orderBy('id')->pluck('value')->all())->toBe(['Хлопок', 'Лён'])
            ->and(ProductAttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $power->id)->value('value_number'))->toEqual('1200.5000')
            ->and(ProductAttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $dry->id)->value('value_bool'))->toBeTruthy();

        // The JSON cache is regenerated: units appended, list joined, да/нет.
        expect($product->fresh()->specs())->toBe([
            ['name' => 'Материал', 'value' => 'Хлопок, Лён'],
            ['name' => 'Мощность', 'value' => '1200.5 Вт'],
            ['name' => 'Водонепроницаемый', 'value' => 'да'],
        ]);

        expect(app(CatalogSettings::class)->attributes_migrated_at)->not->toBeNull();
    });

    it('creates non-filterable attributes for more than 200 distinct values', function (): void {
        foreach (range(1, 201) as $i) {
            attrJsonProduct([['Артикул производителя', 'Код '.$i]]);
        }

        attrMigrator()->run();

        $attribute = Attribute::query()->where('name', 'Артикул производителя')->firstOrFail();

        expect($attribute->is_filterable)->toBeFalse()->and($attribute->type)->toBe(AttributeType::List);
    });

    it('drops values that do not fit a number type, backs the original JSON up and restores it on demand', function (): void {
        foreach (range(1, 40) as $i) {
            attrJsonProduct([['Вес, кг', (string) $i]]);
        }
        $original = [['name' => 'Вес, кг', 'value' => 'около тонны']];
        $odd = Product::factory()->create(['attributes' => $original]);
        $mixed = Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный'], ['name' => 'Вес, кг', 'value' => 'тяжёлый']]]);

        attrMigrator()->run();

        $weight = Attribute::query()->where('name', 'Вес')->firstOrFail();

        // 41 of 42 numeric -> number; the odd values are skipped.
        expect($weight->type)->toBe(AttributeType::Number)
            ->and(ProductAttributeValue::query()->where('attribute_id', $weight->id)->count())->toBe(40)
            ->and(ProductAttributeValue::query()->where('product_id', $odd->id)->count())->toBe(0)
            // No stale JSON that a later sync would wipe silently: it is rebuilt from the rows.
            ->and($odd->fresh()->specs())->toBe([])
            ->and($mixed->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный']]);

        // The originals are in the backup (one JSONL line per touched product).
        $files = Storage::disk('local')->files(AttributeJsonBackup::DIRECTORY);
        expect($files)->toHaveCount(1)->and($files[0])->toMatch('/backup-.+\.jsonl$/');
        $lines = collect(explode("\n", trim(Storage::disk('local')->get($files[0]))))->map(fn (string $l): array => json_decode($l, true))->keyBy('product_id');

        expect($lines)->toHaveCount(42)
            ->and($lines[$odd->id]['attributes'])->toBe($original)
            ->and($lines[$mixed->id]['attributes'])->toHaveCount(2);

        // Restore one product: the JSON is back and its rows are gone, so a re-run migrates it again.
        $this->artisan('shop:attributes-restore-json', ['--product' => $mixed->id])->assertSuccessful();

        expect(json_decode((string) DB::table('products')->where('id', $mixed->id)->value('attributes'), true))->toBe($lines[$mixed->id]['attributes'])
            ->and(ProductAttributeValue::query()->where('product_id', $mixed->id)->count())->toBe(0)
            ->and(ProductAttributeValue::query()->count())->toBe(40);

        $this->artisan('shop:attributes-restore-json')->assertSuccessful();

        expect($odd->fresh()->attributes)->toBe($original)
            ->and(ProductAttributeValue::query()->count())->toBe(0);

        // The restored products migrate again; the backup keeps the first original.
        attrMigrator()->run();
        expect(ProductAttributeValue::query()->count())->toBe(40 + 1);
        $again = collect(explode("\n", trim(Storage::disk('local')->get(Storage::disk('local')->files(AttributeJsonBackup::DIRECTORY)[0]))));
        expect($again)->toHaveCount(42);
    });

    it('never overwrites a backed up product: the first original wins across runs', function (): void {
        $product = attrJsonProduct([['Цвет', 'Красный']]);
        $backup = new AttributeJsonBackup;

        $backup->append('a', [$product->id => [['name' => 'Цвет', 'value' => 'Красный']]]);
        // A later run sees rebuilt JSON; a new instance reads the earlier file.
        (new AttributeJsonBackup)->append('b', [$product->id => [['name' => 'Цвет', 'value' => 'Другой']]]);

        expect(Storage::disk('local')->files(AttributeJsonBackup::DIRECTORY))->toHaveCount(1)
            ->and($backup->originals()[$product->id])->toBe([['name' => 'Цвет', 'value' => 'Красный']]);
    });

    it('reports in the dry run how many values and products would lose data, with examples', function (): void {
        foreach (range(1, 40) as $i) {
            attrJsonProduct([['Вес, кг', (string) $i]]);
        }
        $odd = attrJsonProduct([['Вес, кг', 'около тонны']]);
        attrJsonProduct([['Цвет', 'Красный']]);

        $report = attrMigrator()->dryRun();

        expect($report->lostValues)->toBe(1)
            ->and($report->lostProducts)->toBe(1)
            ->and($report->lostExamples)->toBe([['product_id' => $odd->id, 'characteristic' => 'Вес, кг', 'value' => 'около тонны']])
            ->and($report->toArray()['loss']['values'])->toBe(1)
            ->and(Storage::disk('local')->files(AttributeJsonBackup::DIRECTORY))->toBe([]);

        // Predicted loss equals the real one.
        attrMigrator()->run();
        expect(ProductAttributeValue::query()->count())->toBe(41);
    });

    it('limits the examples of the dry run to ten', function (): void {
        foreach (range(1, 300) as $i) {
            attrJsonProduct([['Вес, кг', (string) $i]]);
        }
        foreach (range(1, 12) as $i) {
            attrJsonProduct([['Вес, кг', 'много '.$i]]);
        }

        $report = attrMigrator()->dryRun();

        expect($report->lostValues)->toBe(12)->and($report->lostProducts)->toBe(12)->and($report->lostExamples)->toHaveCount(10);
    });

    it('reports no loss when every value fits', function (): void {
        attrJsonProduct([['Цвет', 'Красный'], ['Мощность, Вт', '500']]);

        expect(attrMigrator()->dryRun()->lostValues)->toBe(0);
    });

    it('is idempotent: a second run creates nothing and changes nothing', function (): void {
        attrJsonProduct([['Цвет', 'Красный'], ['Мощность, Вт', '500']]);
        attrJsonProduct([['Цвет', 'Синий'], ['Мощность, Вт', '600']]);

        attrMigrator()->run();
        $counts = [Attribute::query()->count(), AttributeValue::query()->count(), ProductAttributeValue::query()->count()];
        $specs = Product::query()->orderBy('id')->get()->map->specs()->all();

        attrMigrator()->run();

        expect([Attribute::query()->count(), AttributeValue::query()->count(), ProductAttributeValue::query()->count()])->toBe($counts)
            ->and(Product::query()->orderBy('id')->get()->map->specs()->all())->toBe($specs);
    });

    it('reuses existing attributes by FL-16 rules so a v1 re-import does not duplicate', function (): void {
        $size = Attribute::factory()->create(['name' => 'Размер, см']);
        $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $product = attrJsonProduct([['Размер, см', 'M'], ['Мощность', '500 Вт']]);

        attrMigrator()->run();

        expect(Attribute::query()->count())->toBe(2)
            ->and(ProductAttributeValue::query()->where('product_id', $product->id)->pluck('attribute_id')->sort()->values()->all())->toBe([$size->id, $power->id]);
    });

    it('skips products that already have rows (the tables are the source of truth)', function (): void {
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $product = attrJsonProduct([['Цвет', 'Красный']]);
        app(AttributeService::class)->setProductValues($product, [['attribute_id' => $color->id, 'values' => 'Зелёный']]);

        $batch = attrMigrator()->migrateBatch(0);

        expect($batch->alreadyMigrated)->toBe(1)->and($batch->migrated)->toBe(0);
        $row = ProductAttributeValue::query()->where('product_id', $product->id)->firstOrFail();
        expect($row->value->value)->toBe('Зелёный');
    });

    it('ignores products without JSON characteristics and malformed rows', function (): void {
        Product::factory()->create(['attributes' => null]);
        Product::factory()->create(['attributes' => []]);
        Product::factory()->create(['attributes' => [['name' => '', 'value' => 'x'], ['name' => 'Цвет', 'value' => ''], ['value' => 'y'], 'junk']]);

        $report = attrMigrator()->dryRun();

        expect($report->products)->toBe(0);
        attrMigrator()->run();
        expect(Attribute::query()->count())->toBe(0)->and(app(CatalogSettings::class)->attributes_migrated_at)->not->toBeNull();
    });
});

describe('batches and progress', function (): void {
    it('migrates in batches after a given id and reports the last id', function (): void {
        $products = collect(range(1, 5))->map(fn (int $i): Product => attrJsonProduct([['Цвет', 'Цвет '.$i]]));
        attrMigrator()->prepare();

        $first = attrMigrator()->migrateBatch(0, 2);
        $second = attrMigrator()->migrateBatch($first->lastId, 2);
        $third = attrMigrator()->migrateBatch($second->lastId, 2);

        expect($first->products)->toBe(2)->and($first->done)->toBeFalse()->and($first->lastId)->toBe($products[1]->id)
            ->and($second->done)->toBeFalse()->and($second->lastId)->toBe($products[3]->id)
            ->and($third->products)->toBe(1)->and($third->done)->toBeTrue()
            ->and(ProductAttributeValue::query()->count())->toBe(5);
    });

    it('stores N of M progress, resumes from the stored position and finishes', function (): void {
        foreach (range(1, 3) as $i) {
            attrJsonProduct([['Цвет', 'Цвет '.$i]]);
        }

        $state = app(AttributeMigrationState::class);
        $seen = [];

        $done = attrMigrator()->run(null, function (array $progress) use (&$seen): void {
            $seen[] = $progress;
        });

        expect($done)->toBeTrue()
            ->and($seen)->not->toBeEmpty()
            ->and($seen[0]['total'])->toBe(3)
            ->and($seen[0]['done'])->toBe(3)
            ->and($state->current())->toBeNull()
            ->and($state->isMigrated())->toBeTrue();
    });

    it('stops after the time budget between batches and the next run finishes the rest', function (): void {
        $category = Category::factory()->create();
        Product::factory()->count(JsonAttributeMigrator::BATCH + 1)->create(['category_id' => $category->id, 'attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);
        $state = app(AttributeMigrationState::class);

        // With a budget the first run only prepares the attributes and counts the products (no run is longer than 40 s).
        expect(attrMigrator()->run(0))->toBeFalse()
            ->and($state->current()['done'])->toBe(0)
            ->and($state->current()['total'])->toBe(JsonAttributeMigrator::BATCH + 1)
            ->and(ProductAttributeValue::query()->count())->toBe(0);

        // A zero budget lets exactly one batch run.
        expect(attrMigrator()->run(0))->toBeFalse()
            ->and($state->current()['done'])->toBe(JsonAttributeMigrator::BATCH)
            ->and($state->current()['total'])->toBe(JsonAttributeMigrator::BATCH + 1)
            ->and($state->isMigrated())->toBeFalse()
            ->and($state->isPending())->toBeTrue();

        expect(attrMigrator()->run(0))->toBeTrue()
            ->and(ProductAttributeValue::query()->count())->toBe(JsonAttributeMigrator::BATCH + 1)
            ->and($state->isMigrated())->toBeTrue()
            ->and($state->current())->toBeNull();
    });

    it('continues an interrupted run where it stopped', function (): void {
        foreach (range(1, 3) as $i) {
            attrJsonProduct([['Цвет', 'Цвет '.$i]]);
        }

        $migrator = attrMigrator();
        $migrator->prepare();
        $state = app(AttributeMigrationState::class);
        $state->begin(3, 1);
        $batch = $migrator->migrateBatch(0, 2);
        $state->advance($batch->lastId, $batch->products, 0);

        expect($state->current()['done'])->toBe(2)->and($state->current()['total'])->toBe(3);

        // A new process picks the stored state up and does only the rest.
        expect(app(JsonAttributeMigrator::class)->run())->toBeTrue()
            ->and(ProductAttributeValue::query()->count())->toBe(3)
            ->and($state->current())->toBeNull();
    });

    it('re-dispatches the job until the migration is done', function (): void {
        Queue::fake();
        $migrator = Mockery::mock(JsonAttributeMigrator::class);
        $migrator->shouldReceive('run')->once()->with(MigrateAttributesFromJson::BUDGET_SECONDS)->andReturn(false);

        (new MigrateAttributesFromJson)->handle($migrator);

        Queue::assertPushed(MigrateAttributesFromJson::class, 1);
    });

    it('does not re-dispatch when the migration finished', function (): void {
        Queue::fake();
        $migrator = Mockery::mock(JsonAttributeMigrator::class);
        $migrator->shouldReceive('run')->once()->andReturn(true);

        (new MigrateAttributesFromJson)->handle($migrator);

        Queue::assertNothingPushed();
    });

    it('runs through the job on the sync queue and sets the flag', function (): void {
        attrJsonProduct([['Цвет', 'Красный']]);

        // The first run prepares, the second one migrates (a job queued from inside a sync job is blocked by the
        // overlap lock, on the database queue it runs after the current one).
        MigrateAttributesFromJson::dispatch();
        MigrateAttributesFromJson::dispatch();

        expect(app(AttributeMigrationState::class)->isMigrated())->toBeTrue()
            ->and(ProductAttributeValue::query()->count())->toBe(1);
    });

    it('runs through the command and prints the progress', function (): void {
        attrJsonProduct([['Цвет', 'Красный']]);

        $this->artisan('shop:attributes-migrate')
            ->expectsOutputToContain('Обработано 1 из 1')
            ->assertSuccessful();

        expect(app(CatalogSettings::class)->attributes_migrated_at)->not->toBeNull()->and(Cache::get(AttributeMigrationState::CACHE_KEY))->toBeNull();
    });
});
