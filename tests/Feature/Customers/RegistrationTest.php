<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Events\CustomerEmailVerified;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Services\Access\CustomerGroupService;
use App\Settings\CustomerSettings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
});

it('shows the registration form with the consent unchecked (CA-02)', function (): void {
    $response = $this->get(route('customer.register'))
        ->assertOk()
        ->assertSee('name="consent"', false)
        ->assertDontSee('name="consent" type="checkbox" value="1" required aria-required="true" checked', false)
        ->assertSee('name="form_token"', false)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    // Personal data pages are never cached (TZ 17.7).
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
});

it('registers an unconfirmed customer in the default group and sends a code (CA-02, CA-03, CG-11)', function (): void {
    $group = CustomerGroup::factory()->create(['name' => 'Новички']);
    app(CustomerGroupService::class)->setDefaultForNew($group);

    $this->post(route('customer.register.store'), registrationBody())
        ->assertRedirect(route('customer.confirm'));

    $customer = Customer::query()->where('email', 'ivan.test@example.com')->firstOrFail();

    expect($customer->customer_group_id)->toBe($group->id)
        ->and($customer->email_verified_at)->toBeNull()
        ->and($customer->phone)->toBe('+79001234567')
        ->and($customer->consent_at)->not->toBeNull()
        ->and($customer->consent_ip)->toBe('127.0.0.1')
        ->and($customer->auth_version)->toBe(1)
        ->and(Hash::check('secret-pass-1', $customer->password))->toBeTrue()
        ->and($customer->password)->not->toContain('secret-pass-1');

    expect(lastSentCode(VerificationPurpose::Register))->toMatch('/^\d{6}$/');
    $this->assertGuest('customer');
});

it('falls back to «Розница» when no group is flagged as default (CG-11)', function (): void {
    CustomerGroup::query()->update(['is_default_for_new' => false]);

    $this->post(route('customer.register.store'), registrationBody())->assertRedirect();

    expect(Customer::query()->firstOrFail()->customer_group_id)->toBe(CustomerGroup::retail()->id);
});

it('confirms the e-mail with the code, signs the customer in and fires the verification event (CA-03, CA-04)', function (): void {
    Event::fake([CustomerEmailVerified::class]);
    $this->post(route('customer.register.store'), registrationBody());
    $code = lastSentCode(VerificationPurpose::Register);

    $this->post(route('customer.confirm.store'), ['code' => $code])->assertRedirect(route('account'));

    $customer = Customer::query()->firstOrFail();
    expect($customer->email_verified_at)->not->toBeNull();
    $this->assertAuthenticatedAs($customer, 'customer');
    Event::assertDispatched(CustomerEmailVerified::class, fn (CustomerEmailVerified $e): bool => $e->customer->is($customer));

    $this->get(route('account'))->assertOk()->assertSee('Иван Тестов');
});

it('rejects a wrong code and stays signed out', function (): void {
    $this->post(route('customer.register.store'), registrationBody());

    $this->post(route('customer.confirm.store'), ['code' => '000000'])->assertSessionHasErrors('code');

    expect(Customer::query()->firstOrFail()->email_verified_at)->toBeNull();
    $this->assertGuest('customer');
});

it('lets the customer ask for the code again, but not within 60 seconds (CA-04)', function (): void {
    $this->post(route('customer.register.store'), registrationBody());

    $this->post(route('customer.confirm.resend'))->assertSessionHasErrors();
    Mail::assertQueuedCount(1);

    $this->travel(61)->seconds();
    $this->post(route('customer.confirm.resend'))->assertSessionHasNoErrors();
    Mail::assertQueuedCount(2);
});

it('sends the confirm page back to the form when nothing is pending', function (): void {
    $this->get(route('customer.confirm'))->assertRedirect(route('customer.register'));
});

it('validates the form (CA-02)', function (array $overrides, string $field): void {
    $this->post(route('customer.register.store'), registrationBody($overrides))->assertSessionHasErrors($field);

    expect(Customer::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'bad e-mail' => [['email' => 'not-an-email'], 'email'],
    'bad phone' => [['phone' => '12345'], 'phone'],
    'short password' => [['password' => 'short1', 'password_confirmation' => 'short1'], 'password'],
    'password differs from the confirmation' => [['password_confirmation' => 'another-pass-1'], 'password'],
    'password equals e-mail' => [['email' => 'same.value@example.com', 'password' => 'same.value@example.com', 'password_confirmation' => 'same.value@example.com'], 'password'],
    'no consent' => [['consent' => ''], 'consent'],
]);

it('uses the minimum password length from the settings (CA-23)', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->password_min_length = 20;
    $settings->save();

    $this->post(route('customer.register.store'), registrationBody())->assertSessionHasErrors('password');
});

it('rejects the honeypot and a form submitted faster than 3 seconds (CA-02)', function (): void {
    $this->post(route('customer.register.store'), registrationBody(['website' => 'http://spam.example']))->assertSessionHasErrors('form');
    $this->post(route('customer.register.store'), registrationBody(['form_token' => formToken(1)]))->assertSessionHasErrors('form');
    $this->post(route('customer.register.store'), registrationBody(['form_token' => '']))->assertSessionHasErrors('form');

    expect(Customer::query()->count())->toBe(0);
});

it('re-registration on an unconfirmed address sends a new code and applies its data only when that code is confirmed (CA-05)', function (): void {
    $this->post(route('customer.register.store'), registrationBody());
    $this->travel(61)->seconds();

    $this->post(route('customer.register.store'), registrationBody([
        'name' => 'Новое Имя',
        'phone' => '+7 901 000-00-01',
        'password' => 'brand-new-pass-2',
        'password_confirmation' => 'brand-new-pass-2',
    ]))->assertRedirect(route('customer.confirm'));

    // The stored account is not touched by a registration that is not confirmed yet.
    expect(Customer::query()->count())->toBe(1);
    $customer = Customer::query()->firstOrFail();
    expect($customer->name)->toBe('Иван Тестов')
        ->and(Hash::check('secret-pass-1', $customer->password))->toBeTrue();
    Mail::assertQueuedCount(2);

    $this->post(route('customer.confirm.store'), ['code' => lastSentCode(VerificationPurpose::Register)])->assertRedirect(route('account'));

    $customer = Customer::query()->firstOrFail();
    expect($customer->name)->toBe('Новое Имя')
        ->and($customer->phone)->toBe('+79010000001')
        ->and($customer->email_verified_at)->not->toBeNull()
        ->and(Hash::check('brand-new-pass-2', $customer->password))->toBeTrue()
        ->and(Hash::check('secret-pass-1', $customer->password))->toBeFalse();
});

it('refuses to register on a confirmed address and keeps that account untouched (CA-05)', function (): void {
    $existing = makeCustomer(['email' => 'ivan.test@example.com', 'name' => 'Владелец']);

    $this->post(route('customer.register.store'), registrationBody())
        ->assertSessionHasErrors('email');

    expect($existing->fresh()->name)->toBe('Владелец');
    Mail::assertNothingQueued();
});

it('limits registrations to 5 per hour per IP with HTTP 429 (CA-12)', function (): void {
    foreach (range(1, 5) as $i) {
        $this->post(route('customer.register.store'), registrationBody(['email' => "limit{$i}@example.com"]))->assertRedirect();
    }

    $this->post(route('customer.register.store'), registrationBody(['email' => 'limit6@example.com']))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertSee('Слишком много попыток');

    expect(Customer::query()->where('email', 'limit6@example.com')->exists())->toBeFalse();
});

it('closes the registration form when registration is switched off (CA-23)', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->registration_enabled = false;
    $settings->save();

    $this->get(route('customer.register'))->assertNotFound();
    $this->post(route('customer.register.store'), registrationBody())->assertNotFound();
    $this->get('/')->assertDontSee('data-account-login', false);
});

it('prefills the e-mail from the order remembered by the thank-you page, never from the URL (CA-27, NF-15)', function (): void {
    $order = Order::factory()->create(['customer_email' => 'guest.order@example.com']);

    $this->get(route('customer.register'))->assertDontSee('guest.order@example.com', false);
    $this->get(route('order.thanks', $order->uuid))->assertOk();
    $this->get(route('customer.register'))->assertSee('value="guest.order@example.com"', false);
    $this->get(route('customer.register', ['email' => '"><script>']))->assertDontSee('<script>', false);
});
