<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderItem;
use App\Settings\LegalSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * CX-10 (TZ 18.9): the button «Скачать бланк заказа (PDF)» only for groups with the allow_order_blank flag; a group
 * without the flag does not see the button and gets 403 on the direct URL.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Cache::flush();
});

function cxBlankOrder(Customer $customer, array $attributes = []): Order
{
    $order = Order::factory()->create($attributes + ['customer_id' => $customer->id, 'number' => 'Z-00555', 'total' => '900.00', 'items_total' => '900.00']);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => 'BL-1', 'name' => 'Набор для бланка', 'qty' => 1, 'price' => '900.00', 'total' => '900.00']);

    return $order;
}

function cxWholesaleCustomer(): Customer
{
    return makeCustomer(['customer_group_id' => CustomerGroup::query()->where('code', 'opt')->value('id')]);
}

it('sets the flag for the group «Опт» only (stage 15 migration)', function (): void {
    expect(CustomerGroup::query()->where('code', 'opt')->value('allow_order_blank'))->toBeTruthy()
        ->and(CustomerGroup::query()->where('code', 'retail')->value('allow_order_blank'))->toBeFalsy()
        ->and(CustomerGroup::query()->where('code', 'guest')->value('allow_order_blank'))->toBeFalsy()
        ->and(CustomerGroup::factory()->create()->refresh()->allow_order_blank)->toBeFalse();
});

it('shows the button and gives the PDF to a group with the flag', function (): void {
    $customer = cxWholesaleCustomer();
    signInCustomer($this, $customer);
    $order = cxBlankOrder($customer);

    $this->get(route('customer.orders.show', $order->uuid))->assertOk()
        ->assertSee('Скачать бланк заказа (PDF)')
        ->assertSee(route('customer.orders.blank', $order->uuid), false);

    $response = $this->get(route('customer.orders.blank', $order->uuid))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Disposition'))->toContain('order-Z-00555.pdf');

    $text = pdfText($response->streamedContent());

    expect($text)->toContain('Бланк заказа № Z-00555')->toContain('Набор для бланка');
});

it('gives an invoice once the bank requisites are filled (CX-10)', function (): void {
    setSettings(LegalSettings::class, ['bank_name' => 'ПАО Тестбанк', 'bik' => '044525225', 'account' => '40702810000000000001']);
    $customer = cxWholesaleCustomer();
    signInCustomer($this, $customer);
    $order = cxBlankOrder($customer);

    $response = $this->get(route('customer.orders.blank', $order->uuid))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect(pdfText($response->streamedContent()))->toContain('Счёт на оплату № Z-00555');
});

it('hides the button and answers 403 on the direct URL for a group without the flag', function (): void {
    $customer = makeCustomer();
    signInCustomer($this, $customer);
    $order = cxBlankOrder($customer);

    $this->get(route('customer.orders.show', $order->uuid))->assertOk()
        ->assertDontSee('Скачать бланк заказа')
        ->assertDontSee('data-order-blank', false);
    $this->get(route('customer.orders.blank', $order->uuid))->assertForbidden();
});

it('follows the flag of the group when an administrator switches it', function (): void {
    $customer = makeCustomer();
    signInCustomer($this, $customer);
    $order = cxBlankOrder($customer);
    $customer->group->forceFill(['allow_order_blank' => true])->save();

    $this->get(route('customer.orders.blank', $order->uuid))->assertOk();
});

it('answers 404 for the order of another customer even in a group with the flag', function (): void {
    $customer = cxWholesaleCustomer();
    signInCustomer($this, $customer);
    $foreign = cxBlankOrder(cxWholesaleCustomer());

    $this->get(route('customer.orders.blank', $foreign->uuid))->assertNotFound();
    $this->get(route('customer.orders.blank', (string) Str::uuid()))->assertNotFound();
});

it('has no blank for an inquiry without prices', function (): void {
    $customer = cxWholesaleCustomer();
    signInCustomer($this, $customer);
    $order = cxBlankOrder($customer, ['total' => null, 'items_total' => null]);

    $this->get(route('customer.orders.show', $order->uuid))->assertDontSee('Скачать бланк заказа');
    $this->get(route('customer.orders.blank', $order->uuid))->assertNotFound();
});

it('redirects a guest to the sign-in page', function (): void {
    $order = cxBlankOrder(cxWholesaleCustomer());

    $this->get(route('customer.orders.blank', $order->uuid))->assertRedirect(route('customer.login'));
});

it('treats a deactivated group as «Розница»: no button and 403 (CG-16, CX-10)', function (): void {
    $customer = cxWholesaleCustomer();
    $order = cxBlankOrder($customer);
    CustomerGroup::query()->where('code', 'opt')->update(['is_active' => false]);
    Cache::flush();
    signInCustomer($this, $customer->refresh());

    $this->get(route('customer.orders.show', $order->uuid))->assertOk()->assertDontSee('Скачать бланк заказа');
    $this->get(route('customer.orders.blank', $order->uuid))->assertForbidden();
});
