<?php

declare(strict_types=1);

use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Enums\MediaSource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Imports\Pages\CreateImport;
use App\Models\Import;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

function csvUpload(array $rows, string $name = 'products.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(ImportFiles::csv($rows)));
}

const WIZARD_HEADER = ['Артикул', 'Название', 'Категория', 'Цена', 'Остаток'];

it('runs the wizard from upload to a finished import', function (): void {
    $user = User::factory()->manager()->create();
    actingAs($user);

    $component = Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload([
            WIZARD_HEADER,
            ['W-1', 'Стол', 'Мебель / Столы', '1 290,50', '3'],
            ['W-2', 'Стул', 'Мебель / Стулья', '990', '5'],
            ['W-3', 'Лампа', 'Свет', 'abc', '1'],
        ]), 'media_source' => 'none'])
        ->call('uploadFile')
        ->assertHasNoErrors();

    $import = Import::query()->sole();

    expect($import->status)->toBe(ImportStatus::Uploaded)
        ->and($import->user_id)->toBe($user->id)
        ->and($import->original_name)->toBe('products.csv')
        ->and($import->file_path)->toStartWith('imports/'.$import->id.'/')
        ->and($import->file_path)->toEndWith('.csv')
        ->and($import->media_source)->toBe(MediaSource::None);

    Storage::disk('local')->assertExists($import->file_path);
    expect(Storage::disk('local')->allFiles('imports/uploads'))->toBe([]);

    // Mapping prefilled from the headers.
    expect($component->get('mapping'))->toBe(['sku', 'name', 'category', 'price', 'stock'])
        ->and($component->get('headers'))->toBe(WIZARD_HEADER);

    $component->call('saveMapping')->assertHasNoErrors();

    $import->refresh();

    // The queue is sync in tests: the analysis already ran.
    expect($import->status)->toBe(ImportStatus::Analyzed)
        ->and($import->analysis['summary']['create'])->toBe(2)
        ->and($import->analysis['summary']['errors'])->toBe(1);

    $component->assertSee('Лампа')->call('startImport')->assertRedirect();

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->created_count)->toBe(2)
        ->and($import->error_count)->toBe(1)
        ->and(Product::query()->where('sku', 'W-1')->value('base_price'))->toBe('1290.50');
});

it('shows only the first mapping step with sample values and warns about a duplicated target', function (): void {
    actingAs(User::factory()->admin()->create());

    $component = Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload([
            ['Артикул', 'Название', 'Код', 'Цвет'],
            ['A-1', 'Стол', 'A-1-X', 'Белый'],
            ['A-2', 'Стул', 'A-2-X', 'Чёрный'],
        ]), 'media_source' => 'none'])
        ->call('uploadFile');

    // «Код» is a synonym of the SKU as well: the second column is ignored and reported.
    expect($component->get('mapping'))->toBe(['sku', 'name', '', ''])
        ->and($component->get('samples'))->toHaveCount(2)
        ->and($component->get('mappingWarnings'))->toHaveCount(1);

    $component->assertSee('A-1-X')->assertSee('Характеристика: Цвет');

    // The user maps the characteristic column and unmaps the SKU: the import cannot start without a SKU.
    $component->set('mapping.3', 'attr:Цвет')->set('mapping.0', '')->call('saveMapping')
        ->assertHasErrors(['mapping']);

    expect(Import::query()->sole()->status)->toBe(ImportStatus::Uploaded);

    $component->set('mapping.0', 'sku')->call('saveMapping')->assertHasNoErrors();

    expect(Import::query()->sole()->mapping)->toBe([0 => 'sku', 1 => 'name', 2 => null, 3 => 'attr:Цвет']);
});

it('remembers the last mapping for the next import with the same headers', function (): void {
    actingAs(User::factory()->admin()->create());

    $rows = [['Номер', 'Товар', 'Цена'], ['A-1', 'Стол', '10']];
    $first = Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload($rows), 'media_source' => 'none'])
        ->call('uploadFile')
        ->set('mapping.0', 'sku')
        ->set('mapping.2', 'cost_price')
        ->call('saveMapping');

    $second = Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload($rows), 'media_source' => 'none'])
        ->call('uploadFile');

    expect($second->get('mapping'))->toBe(['sku', 'name', 'cost_price']);
});

it('rejects files with a wrong extension or content', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(CreateImport::class)
        ->fillForm(['file' => UploadedFile::fake()->createWithContent('products.php', '<?php echo 1;'), 'media_source' => 'none'])
        ->call('uploadFile')
        ->assertHasErrors();

    // A ZIP renamed to .csv is not a CSV.
    Livewire::test(CreateImport::class)
        ->fillForm(['file' => UploadedFile::fake()->createWithContent('products.csv', "PK\x03\x04junk"), 'media_source' => 'none'])
        ->call('uploadFile')
        ->assertHasErrors();

    expect(Import::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('imports'))->toBe([]);
});

it('stores the photo ZIP privately and keeps the server entry only from the real list', function (): void {
    actingAs(User::factory()->admin()->create());

    $zip = UploadedFile::fake()->createWithContent('photos.zip', (string) file_get_contents(ImportHarness::zip(['a.jpg' => 'x'])));

    Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload([WIZARD_HEADER, ['Z-1', 'Стол', '', '1', '1']]), 'media_source' => 'zip', 'media_zip' => $zip])
        ->call('uploadFile')
        ->assertHasNoErrors();

    $import = Import::query()->sole();

    expect($import->media_source)->toBe(MediaSource::Zip)
        ->and($import->media_path)->toStartWith('imports/'.$import->id.'/')
        ->and($import->media_path)->toEndWith('.zip');
    Storage::disk('local')->assertExists($import->media_path);

    // A server entry that is not in storage/app/import-media is refused.
    Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload([WIZARD_HEADER, ['Z-1', 'Стол', '', '1', '1']]), 'media_source' => 'server_dir', 'media_server_entry' => '../../.env'])
        ->call('uploadFile')
        ->assertHasErrors();

    expect(Import::query()->count())->toBe(1);
});

it('offers the full synchronization only to administrators and rejects it server side for managers', function (): void {
    $manager = User::factory()->manager()->create();
    actingAs($manager);

    $component = Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload([WIZARD_HEADER, ['F-1', 'Стол', '', '1', '1']]), 'media_source' => 'none'])
        ->call('uploadFile');

    expect($component->instance()->modeOptions())->not->toHaveKey('full_sync');

    // Forced by hand even though the select does not offer it.
    $component->set('options.mode', 'full_sync')->call('saveMapping')->assertHasErrors(['options.mode']);

    $import = Import::query()->sole();
    expect($import->status)->toBe(ImportStatus::Uploaded)
        ->and($import->mode)->toBe(ImportMode::CreateUpdate);

    // An import already switched to full sync in the database cannot be started by a manager either.
    $import->forceFill(['status' => ImportStatus::Analyzed, 'mode' => ImportMode::FullSync])->save();
    Livewire::withQueryParams(['import' => $import->id])->test(CreateImport::class)
        ->set('confirmFullSync', true)
        ->call('startImport');

    expect($import->refresh()->status)->toBe(ImportStatus::Analyzed);
});

it('lets an administrator run a full sync only after the explicit confirmation', function (): void {
    actingAs(User::factory()->admin()->create());
    Product::factory()->count(3)->create(['is_active' => true]);
    $keep = Product::factory()->create(['sku' => 'F-1', 'is_active' => true]);

    $component = Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload([WIZARD_HEADER, ['F-1', 'Стол', '', '1', '1']]), 'media_source' => 'none'])
        ->call('uploadFile')
        ->set('options.mode', 'full_sync')
        ->call('saveMapping')
        ->assertHasNoErrors();

    $component->assertSee('Будут скрыты товары, которых нет в файле: 3');

    $component->call('startImport')->assertHasErrors(['confirmFullSync']);
    expect(Import::query()->sole()->status)->toBe(ImportStatus::Analyzed);

    $component->set('confirmFullSync', true)->call('startImport')->assertHasNoErrors();

    expect(Product::query()->where('is_active', true)->count())->toBe(1)
        ->and($keep->refresh()->is_active)->toBeTrue();
});

it('does not let another user tamper with the import id', function (): void {
    actingAs(User::factory()->admin()->create());

    $component = Livewire::test(CreateImport::class)
        ->fillForm(['file' => csvUpload([WIZARD_HEADER, ['T-1', 'Стол', '', '1', '1']]), 'media_source' => 'none'])
        ->call('uploadFile');

    // importId is a locked property.
    expect(fn () => $component->set('importId', 999))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('keeps guests and inactive users out of the wizard', function (): void {
    $this->get(ImportResource::getUrl('create'))->assertRedirect();

    actingAs(User::factory()->manager()->create(['is_active' => false]));
    $this->get(ImportResource::getUrl('create'))->assertForbidden();
});

it('opens for administrators and managers and resumes an import from the query string', function (string $role): void {
    $user = User::factory()->{$role}()->create();
    actingAs($user);
    $this->get(ImportResource::getUrl('create'))->assertOk()->assertSee('Скачать шаблон');

    $import = Import::factory()->create(['user_id' => $user->id]);
    Storage::disk('local')->put($import->file_path = 'imports/'.$import->id.'/resume.csv', (string) file_get_contents(ImportFiles::csv([WIZARD_HEADER, ['R-1', 'Стол', '', '1', '1']])));
    $import->save();

    $this->get(ImportResource::getUrl('create').'?import='.$import->id)
        ->assertOk()
        ->assertSee('Сопоставление колонок')
        ->assertSee('R-1');
})->with(['admin', 'manager']);

it('lets the user pick the sheet when the workbook has several', function (): void {
    actingAs(User::factory()->admin()->create());

    $path = ImportFiles::xlsx([
        'Справка' => [['Текст'], ['Это справка']],
        'Прайс' => [['Артикул', 'Название', 'Цена'], ['S-1', 'Стол', '10']],
        'Склад' => [['Код', 'Остаток'], ['S-1', '5']],
    ]);

    $component = Livewire::test(CreateImport::class)
        ->fillForm(['file' => UploadedFile::fake()->createWithContent('book.xlsx', (string) file_get_contents($path)), 'media_source' => 'none'])
        ->call('uploadFile')
        ->assertHasNoErrors();

    // The product sheet is guessed (the first one that is not «Справка»/«Категории»).
    expect($component->get('sheetNames'))->toBe(['Справка', 'Прайс', 'Склад'])
        ->and($component->get('sheet'))->toBe('Прайс')
        ->and($component->get('headers'))->toBe(['Артикул', 'Название', 'Цена']);

    $component->set('sheet', 'Склад');

    expect($component->get('headers'))->toBe(['Код', 'Остаток'])
        ->and($component->get('mapping'))->toBe(['sku', 'stock']);

    $component->set('options.mode', 'prices_stock')->call('saveMapping')->assertHasNoErrors();

    $import = Import::query()->sole();
    expect($import->sheet)->toBe('Склад')
        ->and($import->mode)->toBe(ImportMode::PricesStock)
        ->and($import->status)->toBe(ImportStatus::Analyzed);
});
