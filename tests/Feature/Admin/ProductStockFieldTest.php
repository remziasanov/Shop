<?php

declare(strict_types=1);

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Orders\OrderService;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    actingAs(User::factory()->admin()->create());
});

it('does not roll back a concurrent deduction when the product is saved without touching the stock (D-052)', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $page = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);

    app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    expect($product->fresh()->stock_qty)->toBe(9);

    $page->fillForm(['name' => 'Renamed'])->call('save')->assertHasNoFormErrors();

    expect($product->fresh()->stock_qty)->toBe(9)->and($product->fresh()->name)->toBe('Renamed');
});

it('writes a stock value changed in the form through StockService', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $page = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);

    app(OrderService::class)->createFromCart(cartWith($product), checkoutData());

    $page->fillForm(['stock_qty' => 20])->call('save')->assertHasNoFormErrors();

    expect($product->fresh()->stock_qty)->toBe(20);

    // Saved again without a further change: stays put even after another deduction.
    app(OrderService::class)->createFromCart(cartWith($product), checkoutData());
    $page->call('save');
    expect($product->fresh()->stock_qty)->toBe(19);
});

it('can stop tracking the stock by clearing the field', function (): void {
    $product = sellable(['stock_qty' => 10]);

    Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm(['stock_qty' => null])->call('save')->assertHasNoFormErrors();

    expect($product->fresh()->stock_qty)->toBeNull();
});

it('sets the initial stock of a new product', function (): void {
    $category = Category::factory()->create();

    Livewire::test(CreateProduct::class)
        ->fillForm(['name' => 'Новый', 'sku' => 'NEW-1', 'category_id' => $category->id, 'base_price' => '10.00', 'stock_qty' => 7])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Product::query()->where('sku', 'NEW-1')->firstOrFail()->stock_qty)->toBe(7);
});
