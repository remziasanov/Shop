<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_miniapp.php';

use App\Enums\SalesMode;
use App\Livewire\Storefront\CheckoutForm;
use App\Services\PhoneVerification\PhoneVerificationService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * TD-30 (D-157, D-170): the SF-44 rule does not know +77… numbers, so where verification is on the four contact forms
 * (checkout, registration, profile, wholesale application) accept them through PhoneVerificationService only when an
 * allowed prefix covers them.
 */
const KZ_PHONE = '+7 701 123-45-67';

beforeEach(function (): void {
    Mail::fake();
    resetFormGuard();
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $this->gateway = phoneStub();
    $this->buy = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 10, 'base_price' => '1000.00']);
});

/** Runs the four forms with one number and tells which of them stopped it on the phone field. */
function kzPhoneErrors(TestCase $test, string $phone): array
{
    $stopped = [];

    $test->post(route('customer.register.store'), registrationBody(['phone' => $phone]));
    session('errors') !== null && array_key_exists('phone', session('errors')['default']['messages'] ?? []) && $stopped[] = 'registration';

    $customer = makeCustomer(['email' => 'kz.owner@example.com']);
    signInCustomer($test, $customer);

    $test->post(route('customer.profile.update'), ['name' => 'Имя', 'phone' => $phone]);
    session('errors') !== null && array_key_exists('phone', session('errors')['default']['messages'] ?? []) && $stopped[] = 'profile';

    $test->post(route('customer.wholesale.store'), [
        'company_name' => 'ООО «Ромашка»', 'inn' => '7707083893', 'kpp' => '770701001', 'contact_name' => 'Пётр',
        'phone' => $phone, 'consent' => '1', 'website' => '', 'form_token' => formToken(),
    ]);
    session('errors') !== null && array_key_exists('phone', session('errors')['default']['messages'] ?? []) && $stopped[] = 'wholesale';

    return $stopped;
}

it('lets a +77 number through registration, profile and wholesale application when the prefix is allowed (TD-30)', function (): void {
    enablePhoneVerification(['allowed_prefixes' => ['+79', '+77']]);

    expect(kzPhoneErrors($this, KZ_PHONE))->toBe([]);
});

it('stops a +77 number in registration, profile and wholesale application when the prefix is not allowed (TD-30)', function (): void {
    enablePhoneVerification(['allowed_prefixes' => ['+79']]);

    expect(kzPhoneErrors($this, KZ_PHONE))->toBe(['registration', 'profile', 'wholesale']);
});

it('stops a +77 number when verification is off: the SF-44 rule stays as it was (SF-44)', function (): void {
    expect(kzPhoneErrors($this, KZ_PHONE))->toBe(['registration', 'profile', 'wholesale']);
});

it('validates the checkout phone with the allowed prefixes (TD-30)', function (): void {
    enablePhoneVerification(['guest_checkout' => 'all', 'allowed_prefixes' => ['+79']]);
    $component = fillCheckout(livewireWithCart(CheckoutForm::class, cartWith($this->buy)), 'samovyvoz', ['phone' => KZ_PHONE]);
    $this->travel(5)->seconds();
    $component->call('submit')->assertHasErrors(['phone']);

    enablePhoneVerification(['guest_checkout' => 'all', 'allowed_prefixes' => ['+79', '+77']]);
    $component = fillCheckout(livewireWithCart(CheckoutForm::class, cartWith($this->buy)), 'samovyvoz', ['phone' => KZ_PHONE]);
    $this->travel(5)->seconds();
    $component->call('submit')->assertHasNoErrors(['phone'])->assertSet('pvActive', true);

    expect($this->gateway->calls[0]['phone'])->toBe('+77011234567');
});

it('keeps Russian numbers working regardless of the prefixes, and takes the canonical form of +77 only via the prefix (TD-30)', function (): void {
    enablePhoneVerification(['allowed_prefixes' => ['+79']]);
    $service = app(PhoneVerificationService::class);

    // A geographic Russian number is not in the prefix list, yet the SF-44 rule accepts it as before.
    expect($service->contactPhone('8 (495) 123-45-67'))->toBe('+74951234567')
        ->and($service->contactPhone(KZ_PHONE))->toBeNull()
        ->and($service->contactPhone('abc'))->toBeNull();

    enablePhoneVerification(['allowed_prefixes' => ['+77']]);

    expect($service->contactPhone(KZ_PHONE))->toBe('+77011234567');
});
