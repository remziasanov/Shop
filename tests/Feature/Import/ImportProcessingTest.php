<?php

declare(strict_types=1);

use App\Enums\EmptyCellPolicy;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Events\ImportFinished;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Jobs\RecalculatePrices;
use App\Models\Category;
use App\Models\Import;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use App\Services\Import\ImportErrorReport;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportStep;
use App\Services\Import\RowImporter;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    // The import jobs are driven by hand; everything else (notifications, price jobs) runs on the sync queue.
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
});

const HEADER = ['Артикул', 'Название', 'Категория', 'Цена', 'Остаток'];

function runCsv(array $rows, array $attributes = [], string $encoding = 'UTF-8'): Import
{
    $import = ImportHarness::analyzed(ImportFiles::csv($rows, ';', $encoding), $attributes);

    return ImportHarness::run($import);
}

it('creates products and the category tree from paths', function () {
    $import = runCsv([
        HEADER,
        ['A-1', 'Смартфон X', 'Электроника / Телефоны / Смартфоны', '12 990', '5'],
        ['A-2', 'Смартфон Y', 'электроника / телефоны / смартфоны', '1290,50', ''],
        ['A-3', 'Кабель', 'Электроника / Аксессуары', '300', '10'],
    ]);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->created_count)->toBe(3)
        ->and($import->rows_processed)->toBe(3)
        ->and($import->chunks_done)->toBe($import->chunks_total)
        ->and(Category::count())->toBe(4);

    $a1 = Product::where('sku', 'A-1')->firstOrFail();
    $a2 = Product::where('sku', 'A-2')->firstOrFail();

    expect($a1->category_id)->toBe($a2->category_id)
        ->and($a1->base_price)->toBe('12990.00')
        ->and($a1->stock_qty)->toBe(5)
        ->and($a2->base_price)->toBe('1290.50')
        ->and($a2->stock_qty)->toBeNull()
        ->and($a1->is_active)->toBeTrue()
        ->and($a1->final_price)->toBe('12990.00');
});

it('does not create categories when the option is off and reports the row', function () {
    Category::factory()->create(['name' => 'Мебель']);

    $import = runCsv([
        HEADER,
        ['A-1', 'Стол', 'Мебель', '100', ''],
        ['A-2', 'Шкаф', 'Неизвестная', '100', ''],
    ], ['options' => ['create_categories' => false]]);

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->created_count)->toBe(1)
        ->and($import->error_count)->toBe(1)
        ->and(Category::count())->toBe(1)
        ->and(Product::where('sku', 'A-2')->exists())->toBeFalse()
        ->and($import->issues()->first()->row_number)->toBe(3);
});

it('updates by SKU and a second run of the same file changes nothing', function () {
    $rows = [
        ['Артикул', 'Название', 'Категория', 'Цена', 'Остаток', 'Бренд', 'Х: Цвет'],
        ['A-1', 'Смартфон X', 'Электроника / Телефоны', '12990', '5', 'Acme', 'чёрный'],
        ['A-2', 'Смартфон Y', 'Электроника / Телефоны', '9990', '2', 'Acme', 'белый'],
    ];

    runCsv($rows);
    $before = Product::orderBy('id')->get(['id', 'sku', 'updated_at', 'base_price', 'stock_qty'])->toArray();
    $categories = Category::count();
    $history = PriceHistory::count();

    $this->travel(5)->minutes();
    $second = runCsv($rows);

    expect(Product::count())->toBe(2)
        ->and(Category::count())->toBe($categories)
        ->and(Product::orderBy('id')->get(['id', 'sku', 'updated_at', 'base_price', 'stock_qty'])->toArray())->toBe($before)
        ->and(PriceHistory::count())->toBe($history)
        ->and($second->updated_count)->toBe(0)
        ->and($second->unchanged_count)->toBe(2)
        ->and($second->analysis['result']['unchanged'])->toBe(2)
        ->and($second->created_count)->toBe(0);
});

it('counts a row as updated only when something changed', function () {
    $rows = [
        ['Артикул', 'Название', 'Категория', 'Цена'],
        ['A-1', 'Смартфон X', 'Электроника', '12990'],
        ['A-2', 'Смартфон Y', 'Электроника', '9990'],
    ];

    runCsv($rows);
    $rows[2][3] = '9991';
    $second = runCsv($rows);

    expect($second->updated_count)->toBe(1)->and($second->unchanged_count)->toBe(1)->and($second->created_count)->toBe(0);
});

it('writes price history with the import source for changed prices', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['sku' => 'A-1', 'base_price' => '100.00', 'stock_qty' => 1]);

    $import = runCsv([HEADER, ['A-1', 'x', 'y', '250,50', '7']], ['user_id' => $user->id, 'mode' => ImportMode::PricesStock]);

    $row = PriceHistory::where('product_id', $product->id)->where('field', 'base_price')->firstOrFail();

    expect($row->source->value)->toBe('import')
        ->and($row->import_id)->toBe($import->id)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->new_value)->toBe('250.50')
        ->and($product->fresh()->stock_qty)->toBe(7);
});

it('in prices_stock mode skips new SKUs and leaves other fields untouched', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create(['sku' => 'A-1', 'name' => 'Original', 'brand' => 'Keep', 'base_price' => '100.00', 'stock_qty' => 1]);

    $import = runCsv([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Остаток', 'Бренд'],
        ['A-1', 'Changed name', 'Other / Cat', '200', '9', 'Changed'],
        ['NEW-1', 'New', 'Other / Cat', '200', '9', ''],
    ], ['mode' => ImportMode::PricesStock]);

    $product->refresh();

    expect($import->updated_count)->toBe(1)
        ->and($import->skipped_count)->toBe(1)
        ->and($product->name)->toBe('Original')
        ->and($product->brand)->toBe('Keep')
        ->and($product->category_id)->toBe($category->id)
        ->and($product->base_price)->toBe('200.00')
        ->and($product->stock_qty)->toBe(9)
        ->and(Product::where('sku', 'NEW-1')->exists())->toBeFalse()
        ->and(Category::count())->toBe(1);
});

it('full sync deactivates products missing from the file and counts them beforehand', function () {
    $category = Category::factory()->create();
    Product::factory()->inCategory($category)->create(['sku' => 'KEEP-1']);
    $gone = Product::factory()->inCategory($category)->count(2)->create();
    $deleted = Product::factory()->inCategory($category)->create();
    $deleted->delete();
    Product::factory()->inCategory($category)->inactive()->create();

    $import = ImportHarness::analyzed(ImportFiles::csv([
        HEADER,
        ['KEEP-1', 'Keep', $category->name, '10', ''],
        ['NEW-1', 'New', $category->name, '10', ''],
    ]), ['mode' => ImportMode::FullSync]);

    expect(app(ImportRunner::class)->fullSyncAffectedCount($import))->toBe(2);

    $import = ImportHarness::run($import);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->analysis['result']['deactivated'])->toBe(2)
        ->and(Product::whereIn('id', $gone->pluck('id'))->where('is_active', true)->count())->toBe(0)
        ->and(Product::where('sku', 'KEEP-1')->value('is_active'))->toBeTrue()
        ->and(Product::where('sku', 'NEW-1')->value('is_active'))->toBeTrue();
});

it('does not deactivate anything when the file has no rows with a SKU', function () {
    Product::factory()->count(2)->create();

    $import = runCsv([HEADER], ['mode' => ImportMode::FullSync]);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and(Product::where('is_active', true)->count())->toBe(2);
});

it('keeps blank cells with the "keep" policy and clears nullable fields with "clear"', function () {
    $make = fn () => Product::factory()->create([
        'sku' => 'A-1', 'name' => 'Name', 'brand' => 'Brand', 'short_description' => 'Short', 'cost_price' => '50.00',
        'compare_at_price' => '150.00', 'stock_qty' => 5, 'base_price' => '100.00', 'meta_title' => 'Meta',
    ]);
    $rows = [['Артикул', 'Название', 'Цена', 'Бренд', 'Краткое описание', 'Закупочная цена', 'Старая цена', 'Остаток', 'Meta title'], ['A-1', '', '', '', '', '', '', '', '']];

    $product = $make();
    runCsv($rows, ['options' => ['empty_cell_policy' => EmptyCellPolicy::Keep->value]]);
    $product->refresh();

    expect($product->brand)->toBe('Brand')
        ->and($product->cost_price)->toBe('50.00')
        ->and($product->stock_qty)->toBe(5)
        ->and($product->meta_title)->toBe('Meta');

    runCsv($rows, ['options' => ['empty_cell_policy' => EmptyCellPolicy::Clear->value]]);
    $product->refresh();

    expect($product->brand)->toBeNull()
        ->and($product->short_description)->toBeNull()
        ->and($product->cost_price)->toBeNull()
        ->and($product->compare_at_price)->toBeNull()
        ->and($product->stock_qty)->toBeNull()
        ->and($product->meta_title)->toBeNull()
        ->and($product->name)->toBe('Name')
        ->and($product->base_price)->toBe('100.00');
});

it('merges characteristics: existing order kept, new ones appended in column order', function () {
    $product = Product::factory()->create(['sku' => 'A-1', 'attributes' => [
        ['name' => 'Материал', 'value' => 'Дерево'], ['name' => 'Цвет', 'value' => 'Белый'], ['name' => 'Вес', 'value' => '5 кг'],
    ]]);

    runCsv([['Артикул', 'Х: Цвет', 'Х: Размер'], ['A-1', 'Чёрный', '10']]);

    expect($product->fresh()->specs())->toBe([
        ['name' => 'Материал', 'value' => 'Дерево'],
        ['name' => 'Цвет', 'value' => 'Чёрный'],
        ['name' => 'Вес', 'value' => '5 кг'],
        ['name' => 'Размер', 'value' => '10'],
    ]);

    // Blank cell: kept by default, dropped with the clear policy.
    runCsv([['Артикул', 'Х: Цвет', 'Х: Размер'], ['A-1', '', '']]);
    expect(collect($product->fresh()->specs())->pluck('name')->all())->toBe(['Материал', 'Цвет', 'Вес', 'Размер']);

    runCsv([['Артикул', 'Х: Цвет', 'Х: Размер'], ['A-1', '', '']], ['options' => ['empty_cell_policy' => 'clear']]);
    expect(collect($product->fresh()->specs())->pluck('name')->all())->toBe(['Материал', 'Вес']);
});

it('sanitizes the description and maps the sales mode', function () {
    runCsv([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Описание', 'Режим продажи', 'Цена по запросу', 'Порядок'],
        ['A-1', 'Стол', 'Мебель', '100', '<p>Текст</p><script>alert(1)</script>', 'заявка', 'да', '7'],
        ['A-2', 'Стул', 'Мебель', '100', '', 'по умолчанию', '', ''],
    ]);

    $a1 = Product::where('sku', 'A-1')->firstOrFail();

    expect($a1->description)->toBe('<p>Текст</p>')
        ->and($a1->sales_mode?->value)->toBe('inquiry')
        ->and($a1->price_on_request)->toBeTrue()
        ->and($a1->sort_order)->toBe(7)
        ->and(Product::where('sku', 'A-2')->firstOrFail()->sales_mode)->toBeNull();
});

it('keeps importing when rows have errors or repeat a SKU', function () {
    $import = runCsv([
        HEADER,
        ['A-1', 'Первый', 'Мебель', '100', '1'],
        ['A-1', 'Дубль', 'Мебель', '100', '1'],
        ['A-2', 'Плохая цена', 'Мебель', 'abc', '1'],
        ['', 'Без артикула', 'Мебель', '100', '1'],
        ['A-3', 'Третий', 'Мебель', '100', '1'],
    ]);

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->created_count)->toBe(2)
        ->and($import->error_count)->toBe(3)
        ->and(Product::pluck('sku')->sort()->values()->all())->toBe(['A-1', 'A-3'])
        ->and($import->issues()->where('level', 'error')->pluck('row_number')->sort()->values()->all())->toBe([3, 4, 5]);
});

it('stores warnings of imported rows', function () {
    $import = runCsv([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Старая цена'],
        ['A-1', 'Стол', 'Мебель', '100', '50'],
    ]);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->warning_count)->toBe(1)
        ->and($import->issues()->where('level', 'warning')->count())->toBe(1)
        ->and(Product::where('sku', 'A-1')->exists())->toBeTrue();
});

it('restores a soft-deleted product with the same SKU instead of creating a new one', function () {
    $product = Product::factory()->create(['sku' => 'A-1', 'name' => 'Old name']);
    $product->delete();

    $import = runCsv([HEADER, ['A-1', 'New name', 'Мебель', '500', '3']]);

    $fresh = Product::withTrashed()->where('sku', 'A-1')->get();

    expect($fresh)->toHaveCount(1)
        ->and($fresh[0]->deleted_at)->toBeNull()
        ->and($fresh[0]->name)->toBe('New name')
        ->and($fresh[0]->final_price)->toBe('500.00')
        ->and($import->updated_count)->toBe(1);
});

it('reads a Windows-1251 CSV end to end', function () {
    $import = runCsv([
        HEADER,
        ['Я-1', 'Стол обеденный «Ёлка»', 'Мебель / Столы', '5 500,00', '2'],
    ], [], 'Windows-1251');

    $product = Product::firstOrFail();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($product->sku)->toBe('Я-1')
        ->and($product->name)->toBe('Стол обеденный «Ёлка»')
        ->and($product->category->name)->toBe('Столы');
});

it('processes the categories sheet before the products', function () {
    $path = ImportFiles::xlsx([
        'Товары' => [HEADER, ['A-1', 'Стол', 'Мебель / Столы', '100', '']],
        'Категории' => [
            ['Путь', 'Описание', 'Порядок', 'Активна', 'Режим продаж', 'Meta title'],
            ['Мебель / Столы', '<p>Описание</p><script>x</script>', '5', 'нет', 'заявка', 'Столы SEO'],
            ['Мебель', '', '', '', '', ''],
        ],
    ]);

    $import = ImportHarness::run(ImportHarness::analyzed($path));
    $tables = Category::where('name', 'Столы')->firstOrFail();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and(Category::count())->toBe(2)
        ->and($tables->description)->toBe('<p>Описание</p>')
        ->and($tables->sort_order)->toBe(5)
        ->and($tables->is_active)->toBeFalse()
        ->and($tables->sales_mode?->value)->toBe('inquiry')
        ->and($tables->meta_title)->toBe('Столы SEO')
        ->and(Product::firstOrFail()->category_id)->toBe($tables->id)
        ->and($import->analysis['result']['categories_created'])->toBe(2)
        ->and($import->analysis['categories_done'])->toBeTrue();
});

it('reports bad rows of the categories sheet without mixing them with product rows', function () {
    $path = ImportFiles::xlsx([
        'Товары' => [HEADER, ['A-1', 'Стол', 'Мебель', '100', ''], ['A-2', 'Стул', 'Мебель', 'bad', '']],
        'Категории' => [['Путь', 'Порядок'], ['Мебель', 'x']],
    ]);

    $import = ImportHarness::run(ImportHarness::analyzed($path));
    $issues = $import->issues()->orderBy('id')->get();

    expect($import->error_count)->toBe(2)
        ->and($issues)->toHaveCount(2)
        ->and($issues->first()->column)->toStartWith('Лист «Категории»')
        ->and($issues->last()->column)->not->toStartWith('Лист «Категории»');
});

it('does nothing to the catalog in a dry run but reports the counts', function () {
    $existing = Product::factory()->create(['sku' => 'A-1', 'base_price' => '100.00']);

    $import = runCsv([
        HEADER,
        ['A-1', 'Changed', 'Мебель', '999', '1'],
        ['A-2', 'New', 'Новая / Ветка', '10', ''],
        ['A-3', 'Bad', 'Мебель', 'abc', ''],
    ], ['dry_run' => true]);

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->created_count)->toBe(1)
        ->and($import->updated_count)->toBe(1)
        ->and($import->error_count)->toBe(1)
        ->and($import->rows_processed)->toBe(3)
        ->and(Product::count())->toBe(1)
        ->and($existing->fresh()->base_price)->toBe('100.00')
        ->and(Category::count())->toBe($existing->category()->count())
        ->and(Category::where('name', 'Новая')->exists())->toBeFalse()
        ->and(PriceHistory::count())->toBe(0)
        ->and(app(CategoryTree::class)->nodes())->toHaveCount(1);
});

it('does not apply a dry run full sync', function () {
    Product::factory()->count(2)->create();

    runCsv([HEADER, ['NEW-1', 'x', 'Мебель', '1', '']], ['mode' => ImportMode::FullSync, 'dry_run' => true]);

    expect(Product::where('is_active', true)->count())->toBe(2);
});

it('claims chunks atomically so a chunk is never taken twice', function () {
    $rows = [HEADER];
    for ($i = 1; $i <= 5; $i++) {
        $rows[] = ['A-'.$i, 'P'.$i, 'Мебель', '10', ''];
    }
    $import = ImportHarness::analyzed(ImportFiles::csv($rows), ['chunk_size' => 2]);
    $runner = app(ImportRunner::class);
    $runner->start($import);

    expect($import->fresh()->chunks_total)->toBe(3);

    // A competing worker (browser fallback) already took chunk 0.
    expect($runner->claimNextChunk($import))->toBe(0);

    for ($i = 0; $i < 10 && $runner->processNext($import->refresh(), 30.0); $i++);

    // Chunk 1 and 2 were processed by us, chunk 0 is still owned by the other worker.
    $import->refresh();
    expect($import->chunks_done)->toBe(2)
        ->and($import->rows_processed)->toBe(3)
        ->and($import->status)->toBe(ImportStatus::Processing)
        ->and($runner->claimNextChunk($import))->toBeNull();

    // The other worker finishes its chunk; the import completes without reprocessing anything.
    app(RowImporter::class)->importChunk($import, 0);
    for ($i = 0; $i < 5 && $runner->processNext($import->refresh(), 30.0); $i++);

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->created_count)->toBe(5)
        ->and($import->rows_processed)->toBe(5)
        ->and(Product::count())->toBe(5);
});

it('does not let two callers process a chunk at once (lock)', function () {
    $import = ImportHarness::analyzed(ImportFiles::csv([HEADER, ['A-1', 'P', 'Мебель', '10', '']]));
    app(ImportRunner::class)->start($import);

    $lock = Cache::lock('import:'.$import->id.':run', 30);
    $lock->get();

    expect(app(ImportRunner::class)->step($import->refresh(), 5.0))->toBe(ImportStep::Busy)
        ->and(Product::count())->toBe(0);

    $lock->release();

    expect(app(ImportRunner::class)->step($import->refresh(), 5.0))->toBe(ImportStep::Chunk)
        ->and(Product::count())->toBe(1);
});

it('re-claims a chunk whose worker died', function () {
    $import = ImportHarness::analyzed(ImportFiles::csv([HEADER, ['A-1', 'P', 'Мебель', '10', '']]));
    $runner = app(ImportRunner::class);
    $runner->start($import);

    expect($runner->claimNextChunk($import))->toBe(0);

    // Lost: nothing happens while the claim is fresh ...
    expect($runner->step($import->refresh(), 5.0))->toBe(ImportStep::Waiting);

    // ... and after the stale timeout the chunk is taken again.
    $this->travel(ImportRunner::STALE_CLAIM_SECONDS + 5)->seconds();

    expect($runner->step($import->refresh(), 5.0))->toBe(ImportStep::Chunk)
        ->and(Product::count())->toBe(1);
});

it('releases the claim when a chunk fails so a retry can process it', function () {
    $import = ImportHarness::analyzed(ImportFiles::csv([HEADER, ['A-1', 'P', 'Мебель', '10', '']]));
    $runner = app(ImportRunner::class);
    $runner->start($import);

    $import->forceFill(['staging_path' => 'imports/missing.jsonl'])->save();
    try {
        $runner->step($import->refresh(), 5.0);
        $this->fail('The chunk should fail.');
    } catch (ErrorException) {
        // expected: the staged file is missing
    }

    expect($import->refresh()->next_chunk)->toBe(0);
});

it('finishes with a bell notification and the ImportFinished event', function () {
    Event::fake([ImportFinished::class]);
    $user = User::factory()->create();

    $import = runCsv([HEADER, ['A-1', 'Стол', 'Мебель', '100', '']], ['user_id' => $user->id, 'original_name' => 'price.csv']);

    Event::assertDispatched(ImportFinished::class, fn (ImportFinished $e) => $e->import->id === $import->id);

    $notification = $user->notifications()->firstOrFail();

    expect($user->notifications()->count())->toBe(1)
        ->and($notification->data['title'])->toBe('Импорт завершён')
        ->and($notification->data['body'])->toContain('price.csv')->toContain('создано 1')
        ->and($import->finished_at)->not->toBeNull();
});

it('notifies about errors and failures', function () {
    $user = User::factory()->create();
    runCsv([HEADER, ['A-1', 'Стол', 'Мебель', 'bad', '']], ['user_id' => $user->id]);

    expect($user->notifications()->firstOrFail()->data['title'])->toBe('Импорт завершён с ошибками');

    $other = User::factory()->create();
    $import = ImportHarness::analyzed(ImportFiles::csv([HEADER, ['A-1', 'Стол', 'Мебель', '1', '']]), ['user_id' => $other->id]);
    app(ImportRunner::class)->start($import);
    app(ImportRunner::class)->fail($import->refresh(), new RuntimeException('secret db detail'));

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_message)->not->toContain('secret')
        ->and($other->notifications()->firstOrFail()->data['title'])->toBe('Импорт не удался');
});

it('queues price recalculation of the touched products', function () {
    $existing = Product::factory()->create(['sku' => 'A-1']);
    $untouched = Product::factory()->create();

    Queue::fake([ProcessImportChunk::class, RecalculatePrices::class]);
    runCsv([HEADER, ['A-1', 'x', 'Мебель', '100', ''], ['NEW', 'New', 'Мебель', '100', '']]);

    $newId = Product::where('sku', 'NEW')->value('id');

    Queue::assertPushed(RecalculatePrices::class, function (RecalculatePrices $job) use ($existing, $newId, $untouched) {
        return in_array($existing->id, $job->productIds, true) && in_array($newId, $job->productIds, true) && ! in_array($untouched->id, $job->productIds, true);
    });
});

it('does not queue recalculation for a dry run', function () {
    Queue::fake([ProcessImportChunk::class, RecalculatePrices::class]);
    runCsv([HEADER, ['A-1', 'x', 'Мебель', '100', '']], ['dry_run' => true]);

    Queue::assertNotPushed(RecalculatePrices::class);
});

it('start dispatches the first job and rejects an import that is not analyzed', function () {
    $import = ImportHarness::analyzed(ImportFiles::csv([HEADER, ['A-1', 'x', 'Мебель', '100', '']]));
    $runner = app(ImportRunner::class);

    $started = $runner->start($import);

    expect($started->status)->toBe(ImportStatus::Queued)
        ->and($started->started_at)->not->toBeNull();
    Queue::assertPushed(ProcessImportChunk::class, 1);

    expect(fn () => $runner->start($import->refresh()))->toThrow(LogicException::class);
});

it('runs the whole pipeline through the queue job', function () {
    $import = ImportHarness::analyzed(ImportFiles::csv([HEADER, ['A-1', 'x', 'Мебель', '100', '']]));
    app(ImportRunner::class)->start($import);

    (new ProcessImportChunk($import->id))->handle(app(ImportRunner::class));

    expect($import->refresh()->status)->toBe(ImportStatus::Completed)
        ->and(Product::count())->toBe(1);
});

it('builds an error report workbook with the extra column', function () {
    $import = runCsv([
        HEADER,
        ['A-1', 'Стол', 'Мебель', '100', '1'],
        ['A-2', 'Плохой', 'Мебель', 'abc', '1'],
    ]);

    $path = app(ImportErrorReport::class)->build($import);
    $rows = (new Reader);
    $rows->open(Storage::disk('local')->path($path));
    $data = [];
    foreach ($rows->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $data[] = $row->toArray();
        }
    }
    $rows->close();

    expect($data)->toHaveCount(3)
        ->and(end($data[0]))->toBe('Ошибка')
        ->and($data[1][5] ?? null)->toBeEmpty()
        ->and($data[2][5])->toContain('Цена');
});
