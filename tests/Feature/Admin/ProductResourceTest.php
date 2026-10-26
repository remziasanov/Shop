<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\ProductImagesRelationManager;
use App\Filament\Resources\Products\Support\PhotoUpload;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Media\ImageProcessor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('public');
    Storage::fake('local');
});

function adminProductImage(string $name = 'photo.jpg', int $w = 900, int $h = 600): UploadedFile
{
    return UploadedFile::fake()->image($name, $w, $h);
}

function adminProductFile(Product $product, string $alt = 'A'): void
{
    $path = sys_get_temp_dir().'/adm-'.uniqid().'.jpg';
    $im = imagecreatetruecolor(800, 600);
    imagejpeg($im, $path, 90);
    app(ImageProcessor::class)->attachToProduct($product, $path, $alt, null, true);
}

/**
 * @return array<string, mixed>
 */
function validProductForm(Category $category, array $overrides = []): array
{
    return array_merge([
        'name' => 'Кресло тестовое',
        'sku' => 'T-001',
        'category_id' => $category->id,
        'base_price' => '1 290,50',
        'is_active' => true,
    ], $overrides);
}

it('renders list, create and edit pages for admin and manager', function (string $role): void {
    $user = User::factory()->{$role}()->create();
    actingAs($user);
    $product = Product::factory()->create();

    Livewire::test(ListProducts::class)->assertOk()->assertCanSeeTableRecords([$product]);
    Livewire::test(CreateProduct::class)->assertOk();
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->assertOk()
        ->assertSchemaStateSet(['name' => $product->name, 'sku' => $product->sku]);

    $this->get(ProductResource::getUrl('index'))->assertOk();
})->with(['admin', 'manager']);

it('creates a product through the form and sets final price from base price', function (): void {
    actingAs(User::factory()->admin()->create());
    $category = Category::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm(validProductForm($category, [
            'specs' => [
                ['name' => 'Цвет', 'value' => 'Белый'],
                ['name' => 'Цвет', 'value' => 'Чёрный'],
            ],
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('sku', 'T-001')->firstOrFail();

    expect($product->base_price)->toBe('1290.50')
        ->and($product->final_price)->toBe('1290.50')
        ->and($product->slug)->not->toBe('')
        ->and($product->specs())->toBe([['name' => 'Цвет', 'value' => 'Белый'], ['name' => 'Цвет', 'value' => 'Чёрный']]);
});

it('uses the fixed price as the final price and keeps an old price', function (): void {
    actingAs(User::factory()->admin()->create());
    $category = Category::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm(validProductForm($category, ['base_price' => '1000', 'fixed_price' => '800', 'compare_at_price' => '1200']))
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('sku', 'T-001')->firstOrFail();

    expect($product->final_price)->toBe('800.00')->and($product->display_old_price)->toBe('1200.00');
});

it('validates required fields, money format and sku uniqueness including trashed products', function (): void {
    actingAs(User::factory()->admin()->create());
    $category = Category::factory()->create();
    $trashed = Product::factory()->create(['sku' => 'DUP-1']);
    $trashed->delete();

    Livewire::test(CreateProduct::class)
        ->fillForm(validProductForm($category, ['sku' => 'DUP-1']))
        ->call('create')
        ->assertHasFormErrors(['sku' => 'unique']);

    Livewire::test(CreateProduct::class)
        ->fillForm(validProductForm($category, ['name' => '', 'base_price' => 'abc', 'category_id' => null]))
        ->call('create')
        ->assertHasFormErrors(['name', 'base_price', 'category_id']);

    Livewire::test(CreateProduct::class)
        ->fillForm(validProductForm($category, ['base_price' => '-5']))
        ->call('create')
        ->assertHasFormErrors(['base_price']);
});

it('allows saving an existing product with its own sku', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create(['sku' => 'KEEP-1']);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['name' => 'Новое имя'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($product->fresh()->name)->toBe('Новое имя');
});

it('sanitizes the description before saving', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create();

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['description' => '<p onclick="x()">Текст</p><script>alert(1)</script><iframe src="//e.com"></iframe>'])
        ->call('save')
        ->assertHasNoFormErrors();

    $description = (string) $product->fresh()->description;

    expect($description)->toContain('Текст')
        ->and($description)->not->toContain('script')
        ->and($description)->not->toContain('onclick')
        ->and($description)->not->toContain('iframe');
});

it('processes photos uploaded with the create form', function (): void {
    actingAs(User::factory()->admin()->create());
    $category = Category::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm(validProductForm($category, ['new_photos' => [adminProductImage('a.jpg'), adminProductImage('b.png')]]))
        ->call('create')
        ->assertHasNoFormErrors();

    $product = Product::where('sku', 'T-001')->firstOrFail();

    expect($product->images)->toHaveCount(2)
        ->and($product->images->first()->alt)->toBe('Кресло тестовое');
    Storage::disk('public')->assertExists($product->images->first()->path_large);
});

it('filters by category including subcategories', function (): void {
    actingAs(User::factory()->admin()->create());
    $parent = Category::factory()->create();
    $child = Category::factory()->childOf($parent)->create();
    $other = Category::factory()->create();
    $inParent = Product::factory()->inCategory($parent)->create();
    $inChild = Product::factory()->inCategory($child)->create();
    $inOther = Product::factory()->inCategory($other)->create();

    Livewire::test(ListProducts::class)
        ->filterTable('category_id', $parent->id)
        ->assertCanSeeTableRecords([$inParent, $inChild])
        ->assertCanNotSeeTableRecords([$inOther]);
});

it('filters by missing photos, stock and discount', function (): void {
    actingAs(User::factory()->admin()->create());
    $withPhoto = Product::factory()->create(['stock_qty' => 5]);
    adminProductFile($withPhoto);
    $noPhoto = Product::factory()->untracked()->create();
    $out = Product::factory()->outOfStock()->create();
    $sale = Product::factory()->onSale()->create(['stock_qty' => 3]);

    Livewire::test(ListProducts::class)
        ->filterTable('no_photos', true)
        ->assertCanSeeTableRecords([$noPhoto, $out, $sale])
        ->assertCanNotSeeTableRecords([$withPhoto]);

    Livewire::test(ListProducts::class)
        ->filterTable('stock', 'in')
        ->assertCanSeeTableRecords([$withPhoto, $sale])
        ->assertCanNotSeeTableRecords([$noPhoto, $out]);

    Livewire::test(ListProducts::class)
        ->filterTable('stock', 'out')
        ->assertCanSeeTableRecords([$out])
        ->assertCanNotSeeTableRecords([$withPhoto, $noPhoto]);

    Livewire::test(ListProducts::class)
        ->filterTable('stock', 'untracked')
        ->assertCanSeeTableRecords([$noPhoto])
        ->assertCanNotSeeTableRecords([$withPhoto, $out]);

    Livewire::test(ListProducts::class)
        ->filterTable('on_sale', true)
        ->assertCanSeeTableRecords([$sale])
        ->assertCanNotSeeTableRecords([$withPhoto, $out]);
});

it('filters by price range, brand, flags and own sales mode', function (): void {
    actingAs(User::factory()->admin()->create());
    $cheap = Product::factory()->create(['base_price' => '100', 'brand' => 'Север', 'is_featured' => true]);
    $mid = Product::factory()->create(['base_price' => '500', 'brand' => 'Уют', 'is_new' => true, 'sales_mode' => SalesMode::Cart]);
    $dear = Product::factory()->create(['base_price' => '5000', 'brand' => 'Уют']);

    Livewire::test(ListProducts::class)
        ->filterTable('price', ['from' => '200', 'to' => '1 000'])
        ->assertCanSeeTableRecords([$mid])
        ->assertCanNotSeeTableRecords([$cheap, $dear]);

    Livewire::test(ListProducts::class)
        ->filterTable('brand', ['Уют'])
        ->assertCanSeeTableRecords([$mid, $dear])
        ->assertCanNotSeeTableRecords([$cheap]);

    Livewire::test(ListProducts::class)
        ->filterTable('flags', ['featured'])
        ->assertCanSeeTableRecords([$cheap])
        ->assertCanNotSeeTableRecords([$mid, $dear]);

    Livewire::test(ListProducts::class)
        ->filterTable('sales_mode', 'cart')
        ->assertCanSeeTableRecords([$mid])
        ->assertCanNotSeeTableRecords([$cheap, $dear]);

    Livewire::test(ListProducts::class)
        ->filterTable('sales_mode', 'inherit')
        ->assertCanSeeTableRecords([$cheap, $dear])
        ->assertCanNotSeeTableRecords([$mid]);
});

it('searches the table by name and sku', function (): void {
    actingAs(User::factory()->admin()->create());
    $a = Product::factory()->create(['sku' => 'ZZ-777', 'name' => 'Лампа настольная']);
    $b = Product::factory()->create(['sku' => 'QQ-111', 'name' => 'Диван угловой']);

    Livewire::test(ListProducts::class)
        ->searchTable('ZZ-777')
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b])
        ->searchTable('Диван')
        ->assertCanSeeTableRecords([$b])
        ->assertCanNotSeeTableRecords([$a]);
});

it('shows trashed products through the trashed filter and restores them', function (): void {
    actingAs(User::factory()->admin()->create());
    $live = Product::factory()->create();
    $trashed = Product::factory()->create();
    $trashed->delete();

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$trashed])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$trashed])
        ->callAction(TestAction::make('restore')->table($trashed));

    expect($trashed->fresh()->trashed())->toBeFalse();
});

it('activates and hides products in bulk', function (): void {
    actingAs(User::factory()->manager()->create());
    $products = Product::factory()->count(3)->create(['is_active' => true]);

    Livewire::test(ListProducts::class)
        ->selectTableRecords($products->pluck('id')->all())
        ->callAction(TestAction::make('deactivate')->table()->bulk());

    expect(Product::where('is_active', false)->count())->toBe(3);

    Livewire::test(ListProducts::class)
        ->selectTableRecords($products->pluck('id')->all())
        ->callAction(TestAction::make('activate')->table()->bulk());

    expect(Product::where('is_active', true)->count())->toBe(3);
});

it('moves products to a category and sets the sales mode and flags in bulk', function (): void {
    actingAs(User::factory()->admin()->create());
    $target = Category::factory()->create();
    $products = Product::factory()->count(2)->create(['is_featured' => true]);
    $untouched = Product::factory()->create();
    $ids = $products->pluck('id')->all();

    Livewire::test(ListProducts::class)
        ->selectTableRecords($ids)
        ->callAction(TestAction::make('move')->table()->bulk(), ['category_id' => $target->id]);

    Livewire::test(ListProducts::class)
        ->selectTableRecords($ids)
        ->callAction(TestAction::make('set_mode')->table()->bulk(), ['sales_mode' => 'inquiry']);

    Livewire::test(ListProducts::class)
        ->selectTableRecords($ids)
        ->callAction(TestAction::make('set_flags')->table()->bulk(), ['featured' => 'off', 'new' => 'on']);

    foreach ($products as $product) {
        $fresh = $product->fresh();
        expect($fresh->category_id)->toBe($target->id)
            ->and($fresh->sales_mode)->toBe(SalesMode::Inquiry)
            ->and($fresh->is_featured)->toBeFalse()
            ->and($fresh->is_new)->toBeTrue();
    }

    expect($untouched->fresh()->category_id)->not->toBe($target->id);

    Livewire::test(ListProducts::class)
        ->selectTableRecords($ids)
        ->callAction(TestAction::make('set_mode')->table()->bulk(), ['sales_mode' => null]);

    expect($products->first()->fresh()->sales_mode)->toBeNull();
});

it('soft deletes and force deletes in bulk, removing photo files', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create();
    adminProductFile($product);
    $path = $product->images()->first()->path_large;
    Storage::disk('public')->assertExists($path);

    Livewire::test(ListProducts::class)
        ->selectTableRecords([$product->id])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Product::find($product->id))->toBeNull();
    Storage::disk('public')->assertExists($path);

    Livewire::test(ListProducts::class)
        ->filterTable('trashed', true)
        ->selectTableRecords([$product->id])
        ->callAction(TestAction::make('forceDelete')->table()->bulk());

    expect(Product::withTrashed()->find($product->id))->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('duplicates a product from the table', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create(['sku' => 'ORIG-1']);
    adminProductFile($product);

    Livewire::test(ListProducts::class)
        ->callAction(TestAction::make('duplicate')->table($product));

    $copy = Product::where('sku', 'ORIG-1-copy')->firstOrFail();

    expect($copy->is_active)->toBeFalse()->and($copy->images()->count())->toBe(1);
    Storage::disk('public')->assertExists($copy->images()->first()->path_large);
});

it('toggles is_active right in the table', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create(['is_active' => true]);

    Livewire::test(ListProducts::class)
        ->call('updateTableColumnState', 'is_active', (string) $product->getKey(), false);

    expect($product->fresh()->is_active)->toBeFalse();
});

it('finds products by sku and name in global search', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create(['sku' => 'GS-4242', 'name' => 'Этажерка', 'base_price' => '1290']);
    $trashed = Product::factory()->create(['sku' => 'GS-9999']);
    $trashed->delete();

    $results = ProductResource::getGlobalSearchResults('GS-4242');

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe('Этажерка')
        ->and($results->first()->details)->toHaveCount(2);
    expect(ProductResource::getGlobalSearchResults('Этажерка'))->toHaveCount(1)
        ->and(ProductResource::getGlobalSearchResults('GS-9999'))->toHaveCount(0);
});

it('uploads photos via the relation manager, reporting a corrupt file separately', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create(['name' => 'Тумба']);
    $corrupt = UploadedFile::fake()->createWithContent('broken.jpg', random_bytes(2000));
    $valid = adminProductImage('good.jpg', 2000, 1000);

    Livewire::test(ProductImagesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->callAction(TestAction::make('upload')->table(), ['files' => [$corrupt, $valid]])
        // Filament pulls the notifications from the session, so only one can be asserted per test.
        ->assertNotified(__('admin_products.photos.failed', ['name' => 'broken.jpg', 'message' => __('catalog.errors.image_corrupt')]));

    $image = $product->images()->get();

    expect($image)->toHaveCount(1)
        ->and($image->first()->alt)->toBe('Тумба')
        ->and($image->first()->width)->toBe(1600);

    foreach (['path_large', 'path_medium', 'path_small'] as $column) {
        Storage::disk('public')->assertExists($image->first()->{$column});
    }

    // Temporary uploads are removed.
    expect(Storage::disk('local')->allFiles('tmp/product-photos'))->toBe([]);
});

it('deletes photo files with the photo and reorders photos', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create();
    adminProductFile($product, 'one');
    adminProductFile($product, 'two');
    [$first, $second] = $product->images()->get()->all();

    Livewire::test(ProductImagesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class])
        ->assertCanSeeTableRecords([$first, $second])
        ->callAction(TestAction::make('delete')->table($first));

    Storage::disk('public')->assertMissing($first->path_large);
    Storage::disk('public')->assertMissing($first->path_small);
    Storage::disk('public')->assertExists($second->path_large);
    expect($product->images()->count())->toBe(1);
});

it('force deleting a product removes its photo files', function (): void {
    actingAs(User::factory()->admin()->create());
    $product = Product::factory()->create();
    adminProductFile($product);
    $path = $product->images()->first()->path_large;

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('delete');
    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->callAction('forceDelete');

    Storage::disk('public')->assertMissing($path);
    expect(Product::withTrashed()->find($product->id))->toBeNull();
});

it('reports the effective upload limit in the helper text', function (): void {
    expect(PhotoUpload::effectiveMaxBytes())
        ->toBeLessThanOrEqual(10 * 1024 * 1024)
        ->toBeGreaterThan(0);
});
