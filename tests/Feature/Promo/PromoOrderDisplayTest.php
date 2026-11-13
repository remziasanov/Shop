<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Mail\NewRequestShopMail;
use App\Mail\OrderPlacedCustomerMail;
use App\Mail\OrderStatusChangedCustomerMail;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Notifications\Messages\NewRequestMessage;
use App\Support\Money;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => false, 'min_order_amount' => 0]);
    PromoCode::factory()->percent(10)->create(['code' => 'SPRING10']);
    $this->order = promoCheckout(promoCart(sellable(['name' => 'Куртка', 'base_price' => '1000.00']), 3, 'SPRING10'), 'kurerskaia-dostavka');
    $this->line = 'Промокод SPRING10';
    $this->amount = '−'.Money::format(30000);
});

it('shows the promo line on the thank-you page (PM-13)', function (): void {
    $this->get(route('order.thanks', $this->order->uuid))->assertOk()
        ->assertSeeHtml('data-discount-line')->assertSee($this->line)->assertSee($this->amount);
});

it('shows the promo line in the customer account (PM-13)', function (): void {
    $customer = makeCustomer();
    signInCustomer($this, $customer);
    $this->order->forceFill(['customer_id' => $customer->id])->save();

    $this->get(route('customer.orders.show', $this->order->uuid))->assertOk()->assertSee($this->line)->assertSee($this->amount);
});

it('shows the promo line in the order e-mails (PM-13, PM-18)', function (): void {
    $placed = (new OrderPlacedCustomerMail($this->order->fresh()))->render();
    $shop = (new NewRequestShopMail($this->order->fresh()))->render();
    $status = (new OrderStatusChangedCustomerMail($this->order->fresh(), OrderStatus::Confirmed))->render();

    foreach ([$placed, $shop, $status] as $html) {
        expect($html)->toContain($this->line)->toContain($this->amount);
    }
});

it('shows the promo line and a manual discount in the e-mail totals', function (): void {
    $this->order->forceFill(['manual_discount' => '50.00'])->save();

    $html = (new OrderPlacedCustomerMail($this->order->fresh()))->render();

    expect($html)->toContain('Скидка')->toContain('−'.Money::format(5000));
});

it('has no discount lines for an order without a code', function (): void {
    $plain = promoCheckout(promoCart(sellable()), 'samovyvoz');

    $this->get(route('order.thanks', $plain->uuid))->assertOk()->assertDontSeeHtml('data-discount-line');
    expect((new OrderPlacedCustomerMail($plain->fresh()))->render())->not->toContain('Промокод');
});

it('says «бесплатная доставка» for a free delivery code', function (): void {
    PromoCode::factory()->freeDelivery()->create(['code' => 'FREESHIP']);
    $order = promoCheckout(promoCart(sellable(), 1, 'FREESHIP'), 'kurerskaia-dostavka');

    $this->get(route('order.thanks', $order->uuid))->assertOk()->assertSee('Промокод FREESHIP')->assertSee('бесплатная доставка');
});

it('puts the code and the discount into the Telegram message (PM-18)', function (): void {
    $html = (new NewRequestMessage)->render(Order::query()->findOrFail($this->order->id));

    expect($html)->toContain($this->line.': '.$this->amount);
});

it('prints the promo line (PM-13)', function (): void {
    actingAs(User::factory()->admin()->create());

    $this->get(route('admin.orders.print', $this->order))->assertOk()->assertSee($this->line)->assertSee($this->amount);
});
