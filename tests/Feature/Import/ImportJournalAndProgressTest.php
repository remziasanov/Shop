<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\ListImports;
use App\Filament\Resources\Imports\Pages\ViewImport;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Product;
use App\Models\User;
use App\Services\Import\ImportReader;
use App\Services\Import\ImportRunner;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    // The import jobs are driven by hand (as the browser fallback does).
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
});

/**
 * @param  list<list<string>>  $rows
 */
function analyzedImport(array $rows, array $attributes = []): Import
{
    return ImportHarness::analyzed(ImportFiles::csv($rows), $attributes);
}

function importWithErrors(array $attributes = []): Import
{
    return ImportHarness::run(analyzedImport([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Остаток'],
        ['J-1', 'Стол', 'Мебель', '100', '1'],
        ['J-2', 'Стул', 'Мебель', 'abc', '1'],
        ['J-3', 'Лампа', 'Свет', '50', '-4'],
    ], $attributes));
}

// ------------------------------------------------------------------ journal (IM-13)

it('lists imports for administrators and managers alike and links to the wizard', function (string $role): void {
    $other = Import::factory()->status(ImportStatus::Completed)->create(['user_id' => User::factory()->manager()->create()->id]);
    $user = User::factory()->{$role}()->create();
    $own = Import::factory()->status(ImportStatus::Failed)->create(['user_id' => $user->id]);
    actingAs($user);

    Livewire::test(ListImports::class)
        ->assertCanSeeTableRecords([$own, $other])
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$own])
        ->assertCanNotSeeTableRecords([$other])
        ->assertTableActionExists('open');

    $this->get(ImportResource::getUrl('index'))->assertOk()->assertSee('Новый импорт');
})->with(['admin', 'manager']);

it('lets only an administrator delete an import and removes its files', function (): void {
    $import = importWithErrors();
    $directory = 'imports/'.$import->id;
    Storage::disk('local')->assertExists($import->file_path);

    actingAs(User::factory()->manager()->create());
    Livewire::test(ListImports::class)->assertTableActionHidden('delete', $import);

    actingAs(User::factory()->admin()->create());
    Livewire::test(ListImports::class)->callTableAction('delete', $import);

    expect(Import::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($directory))->toBeFalse();
});

it('follows the access matrix in the policy', function (): void {
    $import = Import::factory()->create();
    $admin = User::factory()->admin()->create();
    $manager = User::factory()->manager()->create();
    $inactive = User::factory()->manager()->create(['is_active' => false]);

    foreach (['viewAny', 'create'] as $ability) {
        expect(Gate::forUser($admin)->allows($ability, $ability === 'create' ? Import::class : Import::class))->toBeTrue()
            ->and(Gate::forUser($manager)->allows($ability, Import::class))->toBeTrue()
            ->and(Gate::forUser($inactive)->allows($ability, Import::class))->toBeFalse();
    }

    expect(Gate::forUser($manager)->allows('view', $import))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('process', $import))->toBeTrue()
        ->and(Gate::forUser($inactive)->allows('process', $import))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('useFullSync', Import::class))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('useFullSync', Import::class))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $import))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('delete', $import))->toBeFalse();

    $running = Import::factory()->status(ImportStatus::Processing)->create();
    expect(Gate::forUser($admin)->allows('delete', $running))->toBeFalse();
});

// ------------------------------------------------------------------ progress page (IM-09, IM-10)

it('polls every two seconds and shows progress counters while an import runs', function (): void {
    $user = User::factory()->manager()->create();
    actingAs($user);
    $import = Import::factory()->status(ImportStatus::Processing)->create([
        'rows_total' => 400, 'rows_processed' => 200, 'chunks_total' => 2, 'chunks_done' => 1,
        'created_count' => 150, 'updated_count' => 40, 'skipped_count' => 5, 'error_count' => 5, 'warning_count' => 2,
        'started_at' => now()->subSeconds(20), 'last_activity_at' => now(),
    ]);

    Livewire::test(ViewImport::class, ['record' => $import->id])
        ->assertSeeHtml('wire:poll.2s')
        ->assertSee('50%')
        ->assertSee('Строк обработано: 200 из 400')
        ->assertSee('150')
        ->assertDontSee('Обработать из браузера');

    $this->get(ImportResource::getUrl('view', ['record' => $import]))->assertOk();
});

it('stops polling and shows the report with the issue table when the import is finished', function (): void {
    actingAs(User::factory()->admin()->create());
    $import = importWithErrors();

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors);

    Livewire::test(ViewImport::class, ['record' => $import->id])
        ->assertDontSeeHtml('wire:poll')
        ->assertSee('Завершён с ошибками')
        ->assertSee('Скачать файл с ошибками')
        ->assertSee('Скачать исходный файл')
        ->assertSee('abc')
        ->assertSee('Строка')
        ->set('levelFilter', 'warning')
        ->assertDontSee('abc');
});

it('offers the browser fallback only when the queue has not moved the import for 60 seconds', function (): void {
    actingAs(User::factory()->manager()->create());

    $fresh = Import::factory()->status(ImportStatus::Queued)->create(['started_at' => now()->subSeconds(30), 'last_activity_at' => now()->subSeconds(30)]);
    $stalled = Import::factory()->status(ImportStatus::Queued)->create(['started_at' => now()->subSeconds(90), 'last_activity_at' => now()->subSeconds(90)]);

    Livewire::test(ViewImport::class, ['record' => $fresh->id])->assertDontSee('Обработать из браузера');
    Livewire::test(ViewImport::class, ['record' => $stalled->id])
        ->assertSee('Обработать из браузера')
        ->assertSee('Не закрывайте вкладку')
        ->assertSeeHtml('data-url="'.route('admin.imports.process-next', $stalled).'"');
});

// ------------------------------------------------------------------ browser fallback endpoint (IM-10)

it('processes the import chunk by chunk through the fallback endpoint', function (): void {
    $import = analyzedImport([
        ['Артикул', 'Название', 'Категория', 'Цена', 'Остаток'],
        ['B-1', 'Стол', 'Мебель', '100', '1'],
        ['B-2', 'Стул', 'Мебель', '200', '2'],
    ], ['chunk_size' => 1]);
    app(ImportRunner::class)->start($import);
    actingAs(User::factory()->manager()->create());

    $url = route('admin.imports.process-next', $import);
    $more = true;
    $calls = 0;

    while ($more && $calls++ < 20) {
        $response = $this->postJson($url)->assertOk()->assertJsonStructure(['more', 'progress' => ['percent', 'rows_processed', 'rows_total', 'status']]);
        $more = $response->json('more');
    }

    expect($more)->toBeFalse()
        ->and($import->refresh()->status)->toBe(ImportStatus::Completed)
        ->and(Product::query()->count())->toBe(2);

    // A finished import is a no-op.
    $this->postJson($url)->assertOk()->assertJson(['more' => false]);
});

it('stops the import with a safe message when the fallback step throws', function (): void {
    $import = analyzedImport([['Артикул', 'Название'], ['E-1', 'Стол']]);
    app(ImportRunner::class)->start($import);
    actingAs(User::factory()->admin()->create());

    // The staged rows disappear: the chunk cannot be read.
    Storage::disk('local')->delete((string) $import->refresh()->staging_path);

    $response = $this->postJson(route('admin.imports.process-next', $import))->assertStatus(500)->assertJson(['more' => false]);

    expect($response->json('error'))->not->toContain('secret')
        ->and($import->refresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->failure_message)->not->toContain('secret');
});

it('protects the fallback endpoint with authentication, authorization and CSRF', function (): void {
    $import = Import::factory()->status(ImportStatus::Queued)->create();
    $url = route('admin.imports.process-next', $import);

    // Guest: redirected to the login page.
    $this->post($url)->assertRedirect();

    // Inactive staff user: no access to the panel.
    actingAs(User::factory()->manager()->create(['is_active' => false]));
    $this->post($url)->assertForbidden();

    // Only POST.
    actingAs(User::factory()->manager()->create());
    $this->get($url)->assertStatus(405);

    // Unknown import.
    $this->postJson(route('admin.imports.process-next', 999999))->assertNotFound();

    $route = app('router')->getRoutes()->getByName('admin.imports.process-next');
    expect(app('router')->gatherRouteMiddleware($route))->toContain(PreventRequestForgery::class);
});

// ------------------------------------------------------------------ downloads (IM-12, IM-13)

it('downloads the source file with the original name and the error report with the «Ошибка» column', function (): void {
    $import = importWithErrors(['original_name' => 'catalog.csv']);
    $import->refresh();
    actingAs(User::factory()->manager()->create());

    $source = $this->get(route('admin.imports.download', ['import' => $import, 'kind' => 'source']))->assertOk();
    expect($source->headers->get('content-disposition'))->toContain('catalog.csv');

    $response = $this->get(route('admin.imports.download', ['import' => $import, 'kind' => 'errors']))->assertOk();
    $path = ImportFiles::tempPath('xlsx');
    file_put_contents($path, $response->streamedContent());

    $reader = app(ImportReader::class);
    $headers = $reader->headers($path);
    $rows = iterator_to_array($reader->open($path));

    expect(end($headers))->toBe('Ошибка')
        ->and($rows[3][5] ?? null)->toContain('Цена')
        ->and($rows[2][5] ?? null)->toBeNull();

    $this->get(route('admin.imports.download', ['import' => $import, 'kind' => 'other']))->assertNotFound();
});

it('has no error report when there are no errors and denies guests', function (): void {
    $clean = ImportHarness::run(analyzedImport([['Артикул', 'Название', 'Категория', 'Цена'], ['C-1', 'Стол', 'Мебель', '10']]));
    expect($clean->status)->toBe(ImportStatus::Completed);

    $this->get(route('admin.imports.download', ['import' => $clean, 'kind' => 'source']))->assertRedirect();

    actingAs(User::factory()->admin()->create());
    $this->get(route('admin.imports.download', ['import' => $clean, 'kind' => 'errors']))->assertNotFound();
    $this->get(route('admin.imports.download', ['import' => $clean, 'kind' => 'source']))->assertOk();
});

it('shows the issues of the report paginated', function (): void {
    actingAs(User::factory()->admin()->create());
    $import = Import::factory()->status(ImportStatus::CompletedWithErrors)->create(['error_count' => 30, 'started_at' => now()->subMinute(), 'finished_at' => now()]);

    foreach (range(1, 30) as $row) {
        ImportIssue::factory()->create(['import_id' => $import->id, 'row_number' => $row + 1, 'level' => 'error', 'message' => 'Проблема № '.$row.'.']);
    }

    Livewire::test(ViewImport::class, ['record' => $import->id])
        ->assertSee('Проблема № 1.')
        ->assertSee('Проблема № 25.')
        ->assertDontSee('Проблема № 26.')
        ->call('gotoPage', 2, 'issuesPage')
        ->assertSee('Проблема № 26.')
        ->assertDontSee('Проблема № 1.');
});
