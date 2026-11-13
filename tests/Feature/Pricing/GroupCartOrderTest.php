<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Enums\PriceSource;
use App\Enums\SalesMode;
use App\Exceptions\CartChangedException;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\CartPage;
use App\Models\Cart;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\ProductPriceTier;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartService;
use App\Services\Orders\OrderService;
use App\Settings\PricingSettings;

beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => false, 'min_order_amount' => 0]);
    $this->group = CustomerGroup::factory()->discount(10)->showRetailPrice()->create(['name' => 'Опт']);
    $this->product = sellable(['name' => 'Лампа', 'base_price' => '1000.00', 'stock_qty' => 1000]);
    // 10+: 800, 50+: 700 (retail 1 000, the group's own price at quantity 1 is 900).
    gpTier($this->product, $this->group, 10, '800.00');
    gpTier($this->product, $this->group, 50, '700.00');
    $this->carts = app(CartService::class);
    $this->cart = Cart::factory()->create();
    app(ViewerContext::class)->actingAs($this->group);
});

function gpLine(Cart $cart)
{
    return app(CartService::class)->summary($cart)->lines[0];
}

it('prices a cart line for the group and the current quantity, at the tier boundary (GP-11)', function (): void {
    $this->carts->add($this->cart, $this->product, 9);
    $line = gpLine($this->cart);

    expect($line->currentPrice)->toBe(90_000)->and($line->lineTotal)->toBe(810_000)
        ->and($line->priceSource)->toBe(PriceSource::GroupDiscount)->and($line->tierMinQty)->toBeNull();

    $this->carts->update($this->cart, $this->product->id, 10);
    $line = gpLine($this->cart);

    expect($line->currentPrice)->toBe(80_000)->and($line->lineTotal)->toBe(800_000)
        ->and($line->priceSource)->toBe(PriceSource::Tier)->and($line->tierMinQty)->toBe(10);

    $this->carts->add($this->cart, $this->product, 40);
    expect(gpLine($this->cart)->currentPrice)->toBe(70_000);

    // Back below the boundary the price goes up again.
    $this->carts->update($this->cart, $this->product->id, 49);
    expect(gpLine($this->cart)->currentPrice)->toBe(80_000);
});

it('prices a guest cart with the retail price (GP-11, no group data)', function (): void {
    app(ViewerContext::class)->actingAs(null);
    $this->carts->add($this->cart, $this->product, 10);

    expect(gpLine($this->cart)->currentPrice)->toBe(100_000)->and(gpLine($this->cart)->priceSource)->toBe(PriceSource::Retail);
});

it('does not call a tier crossing by the customer a price change (SF-42, GP-11)', function (): void {
    $this->carts->add($this->cart, $this->product, 5);
    $this->carts->update($this->cart, $this->product->id, 10);

    $line = gpLine($this->cart);
    $item = $this->cart->items()->first();

    expect($line->priceChanged)->toBeFalse()->and($item->price_at_add)->toBe('800.00')
        ->and(app(CartService::class)->summary($this->cart)->hasChanges)->toBeFalse();
});

it('keeps a real price change visible when the quantity changes inside one tier (SF-42)', function (): void {
    $this->carts->add($this->cart, $this->product, 2);   // 900 at add
    $this->product->update(['base_price' => '1100.00']);  // the group price becomes 990
    $this->carts->update($this->cart, $this->product->id, 3);

    $line = gpLine($this->cart);

    expect($line->priceChanged)->toBeTrue()->and($line->priceAtAdd)->toBe(90_000)->and($line->currentPrice)->toBe(99_000);

    // A change of the tier price shows up as well.
    $this->carts->update($this->cart, $this->product->id, 10);
    expect(gpLine($this->cart)->priceChanged)->toBeFalse();
    ProductPriceTier::query()->where('min_qty', 10)->update(['price' => '750.00']);
    expect(gpLine($this->cart)->priceChanged)->toBeTrue()->and(gpLine($this->cart)->currentPrice)->toBe(75_000);
});

it('flags the cart prices when the customer moves to another group (CG-11)', function (): void {
    app(ViewerContext::class)->actingAs(null);
    $this->carts->add($this->cart, $this->product, 2);
    expect(gpLine($this->cart)->priceChanged)->toBeFalse();

    app(ViewerContext::class)->actingAs($this->group);
    $line = gpLine($this->cart);

    expect($line->priceChanged)->toBeTrue()->and($line->priceAtAdd)->toBe(100_000)->and($line->currentPrice)->toBe(90_000);
});

it('hints the next tier when it is close (GP-11)', function (): void {
    $this->carts->add($this->cart, $this->product, 8);
    $hint = gpLine($this->cart)->tierHint;

    expect($hint)->not->toBeNull()->and($hint->missingQty)->toBe(2)->and($hint->minQty)->toBe(10)->and($hint->price)->toBe(80_000);

    // Rendered on the cart page.
    livewireWithCart(CartPage::class, $this->cart)->assertSeeHtml('data-tier-hint')->assertSee('Ещё 2 шт')->assertSeeText("800\u{00A0}₽");
});

it('gives no hint when the tier is far, reached, or the hint is off (GP-11, GP-19)', function (): void {
    $this->carts->add($this->cart, $this->product, 2);   // 8 missing of 2 pieces: over 50 % and over... 8 <= 20 pieces, so it is hinted
    expect(gpLine($this->cart)->tierHint?->missingQty)->toBe(8);

    $settings = app(PricingSettings::class);
    $settings->tier_hint_qty = 3;
    $settings->tier_hint_percent = 50;
    $settings->save();
    expect(gpLine($this->cart)->tierHint)->toBeNull();       // 8 > 3 pieces and 8 > 50 % of 2

    $this->carts->update($this->cart, $this->product->id, 10);
    expect(gpLine($this->cart)->tierHint)->toBeNull();       // 50+ is 40 missing: far

    $settings->tier_hint_enabled = false;
    $settings->save();
    $this->carts->update($this->cart, $this->product->id, 9);
    expect(gpLine($this->cart)->tierHint)->toBeNull();
});

it('does not hint towards a tier that is not cheaper', function (): void {
    $other = sellable(['base_price' => '1000.00', 'stock_qty' => 100]);
    gpTier($other, $this->group, 10, '950.00');   // above the group price 900
    $cart = Cart::factory()->create();
    $this->carts->add($cart, $other, 9);

    expect(gpLine($cart)->tierHint)->toBeNull();
});

it('compares the minimum order with the group prices including tiers (GP-22, CG-08)', function (): void {
    $this->group->update(['min_order_amount' => '5000.00']);
    app(ViewerContext::class)->actingAs($this->group->fresh());

    $this->carts->add($this->cart, $this->product, 5);   // 5 x 900 = 4 500
    $summary = $this->carts->summary($this->cart);

    expect($summary->itemsTotal)->toBe(450_000)->and($summary->minOrderAmount)->toBe(500_000)->and($summary->minOrderMissing)->toBe(50_000)->and($summary->canCheckout)->toBeFalse();

    $this->carts->update($this->cart, $this->product->id, 7);  // 7 x 900 = 6 300
    expect($this->carts->summary($this->cart)->canCheckout)->toBeTrue();

    // 4 pieces at 900 are below the minimum, but the whole sale at a tier price must be counted at the tier price.
    $cheap = sellable(['base_price' => '100.00', 'stock_qty' => 1000]);
    gpTier($cheap, $this->group, 50, '90.00');
    $cart = Cart::factory()->create();
    $this->carts->add($cart, $cheap, 50);               // 50 x 90 (tier), a group discount would give 50 x 90 too; retail would be 5 000
    expect($this->carts->summary($cart)->itemsTotal)->toBe(450_000)->and($this->carts->summary($cart)->canCheckout)->toBeFalse();
});

it('refuses checkout below the minimum counted at group prices (GP-22)', function (): void {
    $this->group->update(['min_order_amount' => '5000.00']);
    app(ViewerContext::class)->actingAs($this->group->fresh());
    $this->carts->add($this->cart, $this->product, 5);

    expect(fn () => app(OrderService::class)->createFromCart($this->cart, checkoutData()))->toThrow(SalesException::class, 'Минимальная сумма');
    expect(Order::query()->count())->toBe(0);
});

it('stores the price source, tier and retail price of every order line (GP-12)', function (): void {
    $plain = sellable(['name' => 'Простая', 'base_price' => '200.00', 'stock_qty' => 100]);
    $this->carts->add($this->cart, $this->product, 12);
    $this->carts->add($this->cart, $plain, 2);

    $order = app(OrderService::class)->createFromCart($this->cart, checkoutData());
    $tier = $order->items->firstWhere('product_id', $this->product->id);
    $discount = $order->items->firstWhere('product_id', $plain->id);

    expect($tier->price)->toBe('800.00')->and($tier->total)->toBe('9600.00')
        ->and($tier->price_source)->toBe(PriceSource::Tier)->and($tier->tier_min_qty)->toBe(10)->and($tier->retail_price)->toBe('1000.00')
        ->and($discount->price)->toBe('180.00')->and($discount->price_source)->toBe(PriceSource::GroupDiscount)->and($discount->tier_min_qty)->toBeNull()
        ->and($discount->retail_price)->toBe('200.00')
        ->and($order->items_total)->toBe('9960.00');
});

it('prices a guest order for «Гость» and keeps no group snapshot (GP-12, GP-13, CA-26)', function (): void {
    app(ViewerContext::class)->actingAs(null);
    $this->carts->add($this->cart, $this->product, 2);

    $order = app(OrderService::class)->createFromCart($this->cart, checkoutData());
    $item = $order->items->first();

    expect($order->customer_id)->toBeNull()->and($order->customer_group_name)->toBeNull()->and($order->customer_group_id)->toBeNull()
        ->and($item->price)->toBe('1000.00')->and($item->price_source)->toBe(PriceSource::Retail)->and($item->retail_price)->toBe('1000.00');
});

it('prices the order of a signed-in customer for their group and stores the snapshot (CA-26, GP-12)', function (): void {
    $customer = makeCustomer(['customer_group_id' => $this->group->id]);
    signInCustomer($this, $customer);
    nextRequest();
    request()->setLaravelSession(app('session.store'));
    app(ViewerContext::class)->actingAs(null);
    app(ViewerContext::class)->beginRequest();

    $cart = $this->carts->getOrCreate(request());
    $this->carts->add($cart, $this->product, 10);
    $order = app(OrderService::class)->createFromCart($cart, checkoutData());

    expect($order->customer_id)->toBe($customer->id)->and($order->customer_group_id)->toBe($this->group->id)->and($order->customer_group_name)->toBe('Опт')
        ->and($order->items->first()->price)->toBe('800.00')->and($order->items->first()->price_source)->toBe(PriceSource::Tier);
});

it('re-resolves the price at checkout and sends the cart back when it changed (GP-13)', function (): void {
    $this->carts->add($this->cart, $this->product, 10);   // 800 per piece
    ProductPriceTier::query()->where('min_qty', 10)->update(['price' => '780.00']);

    try {
        app(OrderService::class)->createFromCart($this->cart, checkoutData());
        $this->fail('Expected CartChangedException');
    } catch (CartChangedException $e) {
        expect($e->reasons)->toHaveCount(1)->and($e->reasons[0])->toContain('780');
    }

    expect(Order::query()->count())->toBe(0)->and($this->cart->items()->first()->price_at_add)->toBe('780.00');

    // The cart is up to date now: the second attempt passes with the new tier price.
    $order = app(OrderService::class)->createFromCart($this->cart, checkoutData());
    expect($order->items->first()->price)->toBe('780.00');
});

it('prices a reservation and an inquiry for the group and the quantity (GP-12)', function (): void {
    $reservation = app(OrderService::class)->createReservation($this->product, reservationData(10));
    $item = $reservation->items->first();

    expect($reservation->type)->toBe(OrderType::Reservation)->and($item->price)->toBe('800.00')->and($item->price_source)->toBe(PriceSource::Tier)
        ->and($reservation->total)->toBe('8000.00');

    $asked = sellable(['name' => 'Заявка', 'base_price' => '1000.00', 'sales_mode' => SalesMode::Inquiry]);
    $inquiry = app(OrderService::class)->createInquiry($asked, inquiryData(2));

    expect($inquiry->items->first()->price)->toBe('900.00')->and($inquiry->items->first()->price_source)->toBe(PriceSource::GroupDiscount);
});

it('keeps the retail behaviour for products without any group data (GP-12)', function (): void {
    $plain = CustomerGroup::factory()->create();
    app(ViewerContext::class)->actingAs($plain);
    $this->carts->add($this->cart, $this->product, 100);

    $order = app(OrderService::class)->createFromCart($this->cart, checkoutData());

    expect($order->items->first()->price)->toBe('1000.00')->and($order->items->first()->price_source)->toBe(PriceSource::Retail);
});

it('recalculates the price, the total and the hint when the quantity changes on the cart page (GP-11)', function (): void {
    $this->carts->add($this->cart, $this->product, 2);
    $key = $this->product->id.'_0';

    livewireWithCart(CartPage::class, $this->cart)
        ->assertSeeHtml('data-tier-hint')
        ->set('qtys.'.$key, 10)
        ->assertSeeText("800\u{00A0}₽")
        ->assertSeeText("8\u{202F}000\u{00A0}₽")
        ->assertDontSeeHtml('data-price-changed');

    expect($this->cart->items()->first()->qty)->toBe(10);
});
