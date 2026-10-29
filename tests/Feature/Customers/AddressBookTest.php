<?php

declare(strict_types=1);

use App\Exceptions\CustomerAccountException;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\CustomerAddress;
use App\Services\Access\ViewerContext;
use App\Services\Customers\CustomerAddressService;

/**
 * CA-09 (TZ 17.3): the address book of the cabinet, at most 10 entries, one default; CA-28: only own entries.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->customer = makeCustomer();
    signInCustomer($this, $this->customer);
});

/** @return array<string, mixed> */
function addressBody(array $overrides = []): array
{
    return array_merge([
        'label' => 'Дом',
        'recipient_name' => 'Иван Тестов',
        'phone' => '8 (900) 123-45-67',
        'address' => 'г. Тестоград, ул. Примерная, 1',
    ], $overrides);
}

it('is closed to guests', function (): void {
    session()->flush();
    nextRequest();

    $this->get(route('customer.addresses'))->assertRedirect(route('customer.login'));
    $this->post(route('customer.addresses.store'), addressBody())->assertRedirect(route('customer.login'));
});

it('adds an address with a normalized phone and makes the first one the default', function (): void {
    $this->post(route('customer.addresses.store'), addressBody())->assertRedirect(route('customer.addresses'));

    $address = CustomerAddress::query()->firstOrFail();

    expect($address->customer_id)->toBe($this->customer->id)
        ->and($address->phone)->toBe('+79001234567')
        ->and($address->is_default)->toBeTrue();

    $this->get(route('customer.addresses'))->assertOk()->assertSee('Дом')->assertSee('г. Тестоград, ул. Примерная, 1');
});

it('keeps exactly one default address', function (): void {
    $this->post(route('customer.addresses.store'), addressBody(['label' => 'Первый']));
    $this->post(route('customer.addresses.store'), addressBody(['label' => 'Второй', 'is_default' => '1']));
    $this->post(route('customer.addresses.store'), addressBody(['label' => 'Третий']));

    $defaults = CustomerAddress::query()->where('is_default', true)->get();

    expect($defaults)->toHaveCount(1)->and($defaults->first()->label)->toBe('Второй');

    $first = CustomerAddress::query()->where('label', 'Первый')->firstOrFail();
    $this->post(route('customer.addresses.default', $first->id))->assertRedirect(route('customer.addresses'));

    expect(CustomerAddress::query()->where('is_default', true)->pluck('label')->all())->toBe(['Первый']);
});

it('promotes another address when the default one is deleted', function (): void {
    $this->post(route('customer.addresses.store'), addressBody(['label' => 'Первый']));
    $this->post(route('customer.addresses.store'), addressBody(['label' => 'Второй']));

    $first = CustomerAddress::query()->where('label', 'Первый')->firstOrFail();
    $this->post(route('customer.addresses.destroy', $first->id))->assertRedirect(route('customer.addresses'));

    expect(CustomerAddress::query()->count())->toBe(1)
        ->and(CustomerAddress::query()->firstOrFail()->is_default)->toBeTrue();
});

it('updates an address', function (): void {
    $this->post(route('customer.addresses.store'), addressBody());
    $address = CustomerAddress::query()->firstOrFail();

    $this->post(route('customer.addresses.update', $address->id), addressBody(['label' => 'Офис', 'address' => 'г. Другой, ул. Новая, 5']))
        ->assertRedirect(route('customer.addresses'));

    expect($address->fresh()->label)->toBe('Офис')->and($address->fresh()->address)->toBe('г. Другой, ул. Новая, 5');
});

it('accepts at most 10 addresses', function (): void {
    CustomerAddress::factory()->count(CustomerAddress::MAX_PER_CUSTOMER)->create(['customer_id' => $this->customer->id]);

    $this->post(route('customer.addresses.store'), addressBody())->assertSessionHasErrors('label');

    expect(CustomerAddress::query()->where('customer_id', $this->customer->id)->count())->toBe(10);
    expect(fn () => app(CustomerAddressService::class)->create($this->customer, addressBody()))->toThrow(CustomerAccountException::class);
});

it('validates the address form', function (): void {
    $this->post(route('customer.addresses.store'), addressBody(['label' => '', 'phone' => '12', 'address' => '']))
        ->assertSessionHasErrors(['label', 'phone', 'address']);
});

it('answers 404 for the address of another customer (CA-28)', function (): void {
    $other = CustomerAddress::factory()->create(['customer_id' => makeCustomer()->id, 'label' => 'Чужой']);

    $this->post(route('customer.addresses.update', $other->id), addressBody(['label' => 'Взлом']))->assertNotFound();
    $this->post(route('customer.addresses.default', $other->id))->assertNotFound();
    $this->post(route('customer.addresses.destroy', $other->id))->assertNotFound();

    expect($other->fresh()->label)->toBe('Чужой');
});

it('fills the checkout with the default address and lets the customer pick another one (CA-09)', function (): void {
    CustomerAddress::factory()->default()->create(['customer_id' => $this->customer->id, 'label' => 'Дом', 'address' => 'Адрес по умолчанию']);
    $second = CustomerAddress::factory()->create(['customer_id' => $this->customer->id, 'label' => 'Офис', 'address' => 'Адрес офиса']);
    $foreign = CustomerAddress::factory()->create(['customer_id' => makeCustomer()->id, 'address' => 'Чужой адрес']);
    sales(['stock_tracking' => true]);
    cartWith(sellable())->forceFill(['customer_id' => $this->customer->id])->save();

    // The state of the checkout component is in its Livewire snapshot.
    $html = $this->get(route('checkout'))->assertOk()->assertSee('data-address-book', false)->getContent();
    preg_match_all('/wire:snapshot="([^"]*)"/', (string) $html, $matches);
    $state = collect($matches[1])
        ->map(fn (string $raw): array => json_decode(html_entity_decode($raw), true) ?: [])
        ->first(fn (array $snapshot): bool => ($snapshot['memo']['name'] ?? '') === 'storefront.checkout-form');

    expect($state['data']['address'])->toBe('Адрес по умолчанию');

    // Picking an entry (the method Livewire calls when the select changes) fills the address; foreign ids are ignored.
    nextRequest();
    request()->setLaravelSession(app('session.store'));
    app(ViewerContext::class)->beginRequest();

    $form = new CheckoutForm;
    $form->addressId = $second->id;
    $form->updatedAddressId($second->id);
    expect($form->address)->toBe('Адрес офиса')->and($form->addressId)->toBe($second->id);

    $form->addressId = $foreign->id;
    $form->updatedAddressId($foreign->id);
    expect($form->address)->toBe('Адрес офиса')->and($form->addressId)->toBeNull();
});

it('fills the recipient and the phone from the picked entry of the address book (CA-09)', function (): void {
    $entry = CustomerAddress::factory()->create([
        'customer_id' => $this->customer->id,
        'recipient_name' => 'Получатель Другой',
        'phone' => '+79001112233',
        'address' => 'Адрес получателя',
    ]);
    sales(['stock_tracking' => true]);
    cartWith(sellable())->forceFill(['customer_id' => $this->customer->id])->save();

    $this->get(route('checkout'))->assertOk();
    nextRequest();
    request()->setLaravelSession(app('session.store'));
    app(ViewerContext::class)->beginRequest();

    $form = new CheckoutForm;
    $form->name = 'Профиль';
    $form->phone = '+79990000000';
    $form->addressId = $entry->id;
    $form->updatedAddressId($entry->id);

    expect($form->name)->toBe('Получатель Другой')
        ->and($form->phone)->toBe('+79001112233')
        ->and($form->address)->toBe('Адрес получателя');
});
