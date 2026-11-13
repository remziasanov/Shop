<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Enums\GroupStockDisplay;
use App\Enums\PriceDisplayMode;
use App\Enums\StorefrontSection;
use App\Jobs\BuildPriceList;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Services\Catalog\CatalogVersion;
use App\Services\Pricing\GroupPriceRecalculator;
use App\Services\Pricing\PriceListBuilder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
    $this->category = Category::factory()->create(['name' => 'Лампы']);
    $this->group = CustomerGroup::factory()->discount(10)->create(['name' => 'Опт', 'code' => 'optovik', 'visible_sections' => [...StorefrontSection::defaults(), StorefrontSection::PriceList->value]]);
    $this->lamp = Product::factory()->inCategory($this->category)->create(['name' => 'Лампа', 'sku' => 'LAMP-1', 'brand' => 'Свет', 'unit' => 'шт', 'base_price' => '1000.00', 'stock_qty' => 12, 'reserved_qty' => 0]);
    gpTier($this->lamp, $this->group, 10, '800.00');
    gpTier($this->lamp, $this->group, 50, '700.00');
    $this->builder = app(PriceListBuilder::class);
});

/**
 * Rows of the first sheet of a stored price list.
 *
 * @return list<list<mixed>>
 */
function gpSheetRows(string $path): array
{
    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();

    return $rows;
}

it('builds the file of a group with the group price, tiers and stock (CG-17)', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard(['name' => 'Куртка', 'brand' => 'Тепло', 'unit' => 'шт'], $size);
    $card->update(['category_id' => $this->category->id]);
    $small = variantOf($card, [$size->values[0]], ['sku' => 'JKT-S', 'barcode' => '4600000000011', 'base_price' => '2000.00', 'stock_qty' => 3]);
    variantOf($card, [$size->values[1]], ['sku' => 'JKT-M', 'base_price' => '2000.00', 'stock_qty' => 0]);
    gpTier($small, $this->group, 5, '1500.00');
    app(GroupPriceRecalculator::class)->recalculate([$card->id]);

    $this->group->update(['stock_display' => GroupStockDisplay::Exact]);
    $rows = $this->builder->buildNow($this->group->fresh());
    $sheet = gpSheetRows(Storage::disk('local')->path($this->builder->path($this->group)));
    $byRow = collect($sheet)->keyBy(fn (array $row): string => (string) $row[0]);

    expect($rows)->toBe(3)->and($sheet[0])->toBe(['Артикул', 'Штрихкод', 'Название', 'Категория', 'Бренд', 'Ед. изм.', 'Остаток', 'Цена', 'Цены от количества'])
        ->and($byRow['LAMP-1'][2])->toBe('Лампа')->and($byRow['LAMP-1'][3])->toBe('Лампы')->and($byRow['LAMP-1'][4])->toBe('Свет')->and($byRow['LAMP-1'][5])->toBe('шт')
        ->and($byRow['LAMP-1'][6])->toBe(12)->and($byRow['LAMP-1'][7])->toBe(900)
        ->and($byRow['LAMP-1'][8])->toBe("от 10: 800\u{00A0}₽; от 50: 700\u{00A0}₽")
        ->and($byRow['JKT-S'][1])->toBe('4600000000011')->and($byRow['JKT-S'][2])->toBe('Куртка (Размер: S)')->and($byRow['JKT-S'][7])->toBe(1800)
        ->and($byRow['JKT-S'][8])->toBe("от 5: 1\u{202F}500\u{00A0}₽")
        ->and($byRow['JKT-M'][6])->toBe('Нет в наличии')->and($byRow['JKT-M'][8])->toBe('');
});

it('shows the stock by the display rule of the group (CG-27)', function (): void {
    $this->group->update(['stock_display' => GroupStockDisplay::YesNo]);
    CatalogVersion::bump();
    $this->builder->buildNow($this->group->fresh());

    $sheet = gpSheetRows(Storage::disk('local')->path($this->builder->path($this->group)));

    expect($sheet[1][6])->toBe('В наличии');
});

it('lists only the products the group may see and leaves price_on_request products without a price (CG-17)', function (): void {
    $hiddenCategory = Category::factory()->create();
    Product::factory()->inCategory($hiddenCategory)->create(['name' => 'Скрытый', 'sku' => 'HID-1', 'base_price' => '10.00']);
    Product::factory()->inCategory($this->category)->create(['name' => 'Неактивный', 'sku' => 'OFF-1', 'is_active' => false, 'base_price' => '10.00']);
    Product::factory()->inCategory($this->category)->create(['name' => 'По запросу', 'sku' => 'REQ-1', 'price_on_request' => true, 'base_price' => '10.00']);
    $this->group->update(['category_access_mode' => CategoryAccessMode::AllExcept]);
    $this->group->categories()->sync([$hiddenCategory->id]);
    CatalogVersion::bump();

    $this->builder->buildNow($this->group->fresh());
    $skus = collect(gpSheetRows(Storage::disk('local')->path($this->builder->path($this->group))))->pluck(0)->all();
    $request = collect(gpSheetRows(Storage::disk('local')->path($this->builder->path($this->group))))->firstWhere(0, 'REQ-1');

    expect($skus)->toContain('LAMP-1', 'REQ-1')->not->toContain('HID-1', 'OFF-1')->and($request[7])->toBe('');
});

it('continues across jobs when the time budget of a job is spent (CG-17, TZ section 2)', function (): void {
    foreach (range(1, 5) as $i) {
        Product::factory()->inCategory($this->category)->create(['name' => 'Товар '.$i, 'sku' => 'T-'.$i, 'base_price' => '100.00']);
    }

    // Chunks of two products and a deadline that is already over: every step reads one chunk and hands over its cursor.
    $builder = $this->builder->withChunk(2);
    $run = 'abc123';
    $builder->begin($this->group, $run);
    $cursor = $builder->step($this->group, $run, 0, microtime(true) - 1);
    $steps = 1;

    expect($cursor)->not->toBeNull();

    while ($cursor !== null) {
        $cursor = $builder->step($this->group, $run, $cursor, microtime(true) - 1);
        $steps++;
    }

    expect($steps)->toBe(4)->and($builder->finish($this->group, $run))->toBe(6);
    $skus = collect(gpSheetRows(Storage::disk('local')->path($builder->path($this->group))))->pluck(0)->slice(1)->sort()->values()->all();

    // Every product exactly once, in spite of the hand-overs.
    expect($skus)->toBe(collect(['LAMP-1', 'T-1', 'T-2', 'T-3', 'T-4', 'T-5'])->sort()->values()->all());
});

it('chains the jobs: a job queues the next one with the cursor and the last one writes the file (CG-17)', function (): void {
    Queue::fake();

    (new BuildPriceList($this->group->id, 'r1'))->handle($this->builder);

    // One chunk covers the catalog: the next job writes the workbook.
    Queue::assertPushed(BuildPriceList::class, fn (BuildPriceList $next): bool => $next->cursor === PriceListBuilder::WRITE && $next->run === 'r1');
    expect($this->builder->exists($this->group))->toBeFalse();

    (new BuildPriceList($this->group->id, 'r1', PriceListBuilder::WRITE))->handle($this->builder);

    expect($this->builder->exists($this->group))->toBeTrue()
        ->and(Storage::disk('local')->files('price-lists/tmp'))->toBe([]);
});

it('skips groups without the section, inactive groups and groups without prices (CG-17)', function (): void {
    $without = CustomerGroup::factory()->create(['code' => 'plain']);
    $inactive = CustomerGroup::factory()->create(['is_active' => false, 'visible_sections' => array_map(fn (StorefrontSection $section): string => $section->value, StorefrontSection::cases())]);
    $hidden = CustomerGroup::factory()->priceDisplay(PriceDisplayMode::Request)->create(['visible_sections' => array_map(fn (StorefrontSection $section): string => $section->value, StorefrontSection::cases())]);

    $codes = $this->builder->eligibleGroups()->pluck('code')->all();

    expect($codes)->toContain('optovik')->not->toContain('plain')->not->toContain($inactive->code)->not->toContain($hidden->code);
});

it('queues one job per eligible group with shop:build-price-lists (CG-17)', function (): void {
    Queue::fake();
    $second = CustomerGroup::factory()->create(['code' => 'dealer', 'visible_sections' => array_map(fn (StorefrontSection $section): string => $section->value, StorefrontSection::cases())]);

    $this->artisan('shop:build-price-lists')->expectsOutputToContain('2')->assertSuccessful();

    Queue::assertPushed(BuildPriceList::class, 2);
    Queue::assertPushed(BuildPriceList::class, fn (BuildPriceList $job): bool => $job->groupId === $this->group->id && $job->cursor === 0);
    Queue::assertPushed(BuildPriceList::class, fn (BuildPriceList $job): bool => $job->groupId === $second->id);
});

it('builds the files at once with --sync (CG-17)', function (): void {
    $this->artisan('shop:build-price-lists', ['--sync' => true])->assertSuccessful();

    expect($this->builder->exists($this->group))->toBeTrue();
});

it('is scheduled daily at 04:30 (CG-17)', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'shop:build-price-lists'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('30 4 * * *');
});

it('serves the price list to a signed-in customer of the group on /account/price-list (CG-17)', function (): void {
    $this->builder->buildNow($this->group);
    gpSignInAs($this, $this->group);

    $response = $this->get(route('customer.price-list'))->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('price-list-optovik.xlsx')
        ->and($response->headers->get('content-type'))->toContain('spreadsheetml');
});

it('answers 404 when the group has no section or no file yet, and sends a guest to the login (CG-04, CG-17)', function (): void {
    $plain = CustomerGroup::factory()->create();
    gpSignInAs($this, $plain);
    $this->get(route('customer.price-list'))->assertNotFound();

    nextRequest();
    gpSignInAs($this, $this->group);
    $this->get(route('customer.price-list'))->assertNotFound();   // section on, file not built yet

    $this->flushSession();
    nextRequest();
    $this->get(route('customer.price-list'))->assertRedirect(route('customer.login'));
});

it('serves /price-list.xlsx only while «Гость» sees the section (CG-17)', function (): void {
    $this->get('/price-list.xlsx')->assertNotFound();

    configureGuest(['visible_sections' => [...StorefrontSection::defaults(), StorefrontSection::PriceList->value]]);
    $this->get('/price-list.xlsx')->assertNotFound();   // not built yet

    $this->builder->buildNow(guestGroup());
    $this->get('/price-list.xlsx')->assertOk();

    configureGuest(['visible_sections' => StorefrontSection::defaults()]);
    $this->get('/price-list.xlsx')->assertNotFound();
});

it('never serves the file of another group (21.7)', function (): void {
    $this->builder->buildNow($this->group);
    configureGuest(['visible_sections' => [...StorefrontSection::defaults(), StorefrontSection::PriceList->value]]);

    // The guest section is on but the guest file is not built: the group's file must not be served instead.
    $this->get('/price-list.xlsx')->assertNotFound();
});

it('limits downloads to 10 per hour (CG-17)', function (): void {
    configureGuest(['visible_sections' => [...StorefrontSection::defaults(), StorefrontSection::PriceList->value]]);
    $this->builder->buildNow(guestGroup());
    RateLimiter::clear('x');

    foreach (range(1, 10) as $i) {
        $this->get('/price-list.xlsx')->assertOk();
    }

    $this->get('/price-list.xlsx')->assertStatus(429);
});

it('shows the price list link in the account only for a group with the section (CG-17)', function (): void {
    gpSignInAs($this, $this->group);
    $this->get(route('account'))->assertOk()->assertSee('data-price-list-link', false);

    nextRequest();
    gpSignInAs($this, CustomerGroup::factory()->create());
    $this->get(route('account'))->assertOk()->assertDontSee('data-price-list-link', false);
});
