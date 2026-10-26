<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Exceptions\SalesException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Cart\CartService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

beforeEach(function () {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $this->carts = app(CartService::class);
    $this->cart = Cart::factory()->create();
});

it('adds a product with the current price and merges repeated adds', function () {
    $product = sellable(['base_price' => '300.00']);

    $this->carts->add($this->cart, $product, 2);
    $item = $this->carts->add($this->cart, $product, 1);

    expect($item->qty)->toBe(3)->and($item->price_at_add)->toBe('300.00')
        ->and($this->cart->items()->count())->toBe(1)
        ->and($this->carts->count($this->cart))->toBe(3);
});

it('refuses products without a cart mode, price on request, showcase and inactive ones (SF-41)', function (array $attributes) {
    $product = sellable($attributes);

    expect(fn () => $this->carts->add($this->cart, $product))->toThrow(SalesException::class)
        ->and($this->cart->items()->count())->toBe(0);
})->with([
    'reservation only' => [['sales_mode' => SalesMode::Reservation]],
    'inquiry' => [['sales_mode' => SalesMode::Inquiry]],
    'showcase' => [['sales_mode' => SalesMode::Showcase]],
    'price on request' => [['price_on_request' => true]],
    'inactive' => [['is_active' => false]],
]);

it('refuses an out of stock product but accepts it with backorder', function () {
    $product = sellable(['stock_qty' => 0]);

    expect(fn () => $this->carts->add($this->cart, $product))->toThrow(SalesException::class, 'нет в наличии');

    sales(['allow_backorder' => true]);
    $item = $this->carts->add($this->cart, $product, 4);

    expect($item->qty)->toBe(4);
});

it('limits the quantity to the available stock', function () {
    $product = sellable(['stock_qty' => 5, 'reserved_qty' => 2]);

    expect($this->carts->add($this->cart, $product, 10)->qty)->toBe(3)
        ->and($this->carts->add($this->cart, $product, 1)->qty)->toBe(3)
        ->and($this->carts->update($this->cart, $product->id, 99)->qty)->toBe(3)
        ->and($this->carts->update($this->cart, $product->id, 2)->qty)->toBe(2);
});

it('updates and removes rows; zero quantity removes', function () {
    $a = sellable();
    $b = sellable();
    $this->carts->add($this->cart, $a);
    $this->carts->add($this->cart, $b);

    expect($this->carts->update($this->cart, $a->id, 0))->toBeNull()
        ->and($this->cart->items()->count())->toBe(1);

    $this->carts->remove($this->cart, $b->id);
    expect($this->cart->items()->count())->toBe(0)
        ->and(fn () => $this->carts->update($this->cart, $b->id, 1))->toThrow(SalesException::class);
});

it('summarizes lines at current prices and flags changes (SF-42)', function () {
    $changed = sellable(['base_price' => '100.00']);
    $same = sellable(['base_price' => '200.00']);
    $gone = sellable(['base_price' => '50.00']);
    $this->carts->add($this->cart, $changed, 2);
    $this->carts->add($this->cart, $same, 1);
    $this->carts->add($this->cart, $gone, 1);
    $changed->update(['base_price' => '150.00']);
    $gone->update(['stock_qty' => 0]);

    $summary = $this->carts->summary($this->cart->fresh());

    expect($summary->lines)->toHaveCount(3)
        ->and($summary->lines[0]->priceChanged)->toBeTrue()
        ->and($summary->lines[0]->priceAtAdd)->toBe(10000)
        ->and($summary->lines[0]->currentPrice)->toBe(15000)
        ->and($summary->lines[0]->lineTotal)->toBe(30000)
        ->and($summary->lines[1]->priceChanged)->toBeFalse()
        ->and($summary->lines[2]->unavailable)->toBeTrue()
        ->and($summary->lines[2]->issue)->toContain('нет в наличии')
        ->and($summary->itemsTotal)->toBe(50000)
        ->and($summary->hasChanges)->toBeTrue();

    $this->carts->acknowledgePrices($this->cart);
    expect($this->carts->summary($this->cart->fresh())->lines[0]->priceChanged)->toBeFalse();
});

it('computes delivery options and the minimum order check (SF-43, SF-45)', function () {
    sales(['min_order_amount' => 100000]);
    $cheap = sellable(['base_price' => '600.00']);
    $this->carts->add($this->cart, $cheap, 1);

    $summary = $this->carts->summary($this->cart);

    expect($summary->minOrderMissing)->toBe(40000)->and($summary->canCheckout)->toBeFalse()
        ->and($summary->deliveryOptions)->toHaveCount(2)
        ->and($summary->deliveryOptions[0]['price'])->toBe(0)
        ->and($summary->deliveryOptions[1]['price'])->toBe(30000)
        ->and($summary->deliveryOptions[1]['free'])->toBeFalse();

    $this->carts->update($this->cart, $cheap->id, 10);
    $summary = $this->carts->summary($this->cart->fresh());

    expect($summary->minOrderMissing)->toBe(0)->and($summary->canCheckout)->toBeTrue()
        ->and($summary->deliveryOptions[1]['price'])->toBe(0)
        ->and($summary->deliveryOptions[1]['free'])->toBeTrue();
});

it('has an empty summary for an empty cart', function () {
    $summary = $this->carts->summary($this->cart);

    expect($summary->isEmpty())->toBeTrue()->and($summary->canCheckout)->toBeFalse();
});

it('finds the cart by cookie and queues the cookie on creation (D-047)', function () {
    $request = Request::create('/');
    expect($this->carts->current($request))->toBeNull();

    $created = $this->carts->getOrCreate($request);
    $queued = Cookie::getQueuedCookies();

    expect($queued)->toHaveCount(1)
        ->and($queued[0]->getName())->toBe('cart')
        ->and($queued[0]->getValue())->toBe($created->token)
        ->and($queued[0]->isHttpOnly())->toBeTrue()
        ->and($queued[0]->getSameSite())->toBe('lax');

    $again = Request::create('/', 'GET', [], ['cart' => $created->token]);
    expect($this->carts->current($again)?->id)->toBe($created->id)
        ->and($this->carts->getOrCreate($again)->id)->toBe($created->id)
        ->and($this->carts->current(Request::create('/', 'GET', [], ['cart' => 'not-a-uuid'])))->toBeNull();
});

it('removes carts that were not touched for 30 days', function () {
    $old = Cart::factory()->create();
    $product = sellable();
    $this->carts->add($old, $product);
    Cart::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(31)]);

    expect($this->carts->clearOld(30))->toBe(1)
        ->and(Cart::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Cart::query()->whereKey($this->cart->id)->exists())->toBeTrue()
        ->and(CartItem::count())->toBe(0);
});
