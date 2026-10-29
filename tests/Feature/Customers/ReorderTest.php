<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;

/**
 * CA-13 (TZ 17.3): «Повторить заказ» adds the available positions at the current price and reports the rest.
 */
beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $this->customer = makeCustomer();
    signInCustomer($this, $this->customer);
});

it('adds the available items, skips the unavailable ones and reports the result', function (): void {
    $ok = sellable(['name' => 'Доступный товар', 'stock_qty' => 10]);
    $gone = sellable(['name' => 'Удалённый товар']);
    $showcase = sellable(['name' => 'Витринный товар', 'sales_mode' => SalesMode::Showcase]);
    $empty = sellable(['name' => 'Закончившийся товар', 'stock_qty' => 0]);

    $order = Order::factory()->create(['customer_id' => $this->customer->id]);

    foreach ([[$ok, 3], [$gone, 1], [$showcase, 1], [$empty, 1]] as [$product, $qty]) {
        OrderItem::factory()->forProduct($product, $qty)->create(['order_id' => $order->id]);
    }

    $gone->delete();

    $response = $this->post(route('customer.orders.repeat', $order->uuid))->assertRedirect(route('cart'));

    $cart = Cart::query()->where('customer_id', $this->customer->id)->firstOrFail();
    $items = CartItem::query()->where('cart_id', $cart->id)->get();

    expect($items)->toHaveCount(1)
        ->and($items->first()->product_id)->toBe($ok->id)
        ->and($items->first()->qty)->toBe(3);

    $this->get(route('cart'))
        ->assertOk()
        ->assertSee('data-reorder-report', false)
        ->assertSee('Добавлено в корзину: 1 из 4.')
        ->assertSee('Удалённый товар')
        ->assertSee('Витринный товар')
        ->assertSee('Закончившийся товар');
});

it('limits the quantity by the current stock and takes the current price', function (): void {
    $product = sellable(['stock_qty' => 2, 'base_price' => '1000.00']);
    $order = Order::factory()->create(['customer_id' => $this->customer->id]);
    OrderItem::factory()->forProduct($product, 5)->create(['order_id' => $order->id, 'price' => '700.00', 'total' => '3500.00']);
    $product->forceFill(['base_price' => '1500.00'])->save();

    $this->post(route('customer.orders.repeat', $order->uuid));

    $item = CartItem::query()->firstOrFail();

    expect($item->qty)->toBe(2)
        ->and($item->price_at_add)->toBe('1500.00');
});

it('adds to the quantity already in the cart instead of duplicating the row', function (): void {
    $product = sellable(['stock_qty' => 10]);
    $cart = cartWith($product);
    $cart->forceFill(['customer_id' => $this->customer->id])->save();
    $order = Order::factory()->create(['customer_id' => $this->customer->id]);
    OrderItem::factory()->forProduct($product, 2)->create(['order_id' => $order->id]);

    $this->post(route('customer.orders.repeat', $order->uuid));

    expect(CartItem::query()->where('cart_id', $cart->id)->count())->toBe(1)
        ->and(CartItem::query()->where('cart_id', $cart->id)->value('qty'))->toBe(3);
});
