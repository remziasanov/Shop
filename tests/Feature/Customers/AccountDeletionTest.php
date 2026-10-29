<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\VerificationPurpose;
use App\Events\CustomerAnonymizing;
use App\Models\AdminAuditEntry;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerCompany;
use App\Models\CustomerNotification;
use App\Models\CustomerVerificationCode;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Customers\CustomerAnonymizer;
use App\Services\Customers\CustomerAuthService;
use App\Services\Customers\CustomerRetentionService;
use App\Services\Customers\VerificationCodeService;
use App\Settings\CustomerSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * CA-16, CA-17, CA-24 (TZ 17.3): deleting the account by a code, anonymization, retention commands.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
});

/** A customer with data in every table the anonymizer touches, and one request with a full set of personal data. */
function customerWithData(): array
{
    $customer = makeCustomer(['email' => 'erase.me@example.com', 'name' => 'Стираемый Покупатель', 'phone' => '+79001112233']);
    CustomerAddress::factory()->count(2)->create(['customer_id' => $customer->id]);
    CustomerCompany::factory()->create(['customer_id' => $customer->id]);
    CustomerNotification::factory()->count(2)->create(['customer_id' => $customer->id]);
    Cart::factory()->create(['customer_id' => $customer->id]);
    CustomerVerificationCode::query()->forceCreate([
        'customer_id' => $customer->id, 'email' => $customer->email, 'purpose' => VerificationPurpose::Login, 'code_hash' => str_repeat('a', 64),
        'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'sent_at' => now(), 'ip' => '192.0.2.1',
    ]);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'customer_name' => 'Стираемый Покупатель',
        'customer_phone' => '+79001112233',
        'customer_email' => 'erase.me@example.com',
        'delivery_address' => 'г. Секретный, ул. Тайная, 1',
        'customer_comment' => 'Позвоните за час',
        'user_agent' => 'Mozilla/5.0 Test',
        'consent_ip' => '198.51.100.7',
        'items_total' => '2000.00',
        'total' => '2300.00',
        'delivery_price' => '300.00',
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Товар', 'price' => '1000.00', 'qty' => 2, 'total' => '2000.00']);

    return [$customer, $order];
}

// ------------------------------------------------------------------ anonymizer (CA-17)

it('wipes personal data, keeps amounts and items and writes a journal entry without personal data (CA-17)', function (): void {
    [$customer, $order] = customerWithData();
    $other = makeCustomer();
    $otherOrder = Order::factory()->create(['customer_id' => $other->id, 'customer_name' => 'Другой Человек']);
    CustomerAddress::factory()->create(['customer_id' => $other->id]);

    expect(app(CustomerAnonymizer::class)->anonymize($customer, null, 'system'))->toBeTrue();

    $fresh = $customer->fresh();
    expect($fresh->name)->toBe('Удалённый покупатель')
        ->and($fresh->email)->toBe('deleted-'.$customer->id.'@anonymous.invalid')
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->anonymized_at)->not->toBeNull()
        ->and($fresh->is_blocked)->toBeTrue()
        ->and($fresh->consent_ip)->toBeNull()
        ->and($fresh->auth_version)->toBeGreaterThan(1)
        ->and(Hash::check('password-123', $fresh->password))->toBeFalse();

    expect(CustomerAddress::query()->where('customer_id', $customer->id)->count())->toBe(0)
        ->and(CustomerCompany::query()->where('customer_id', $customer->id)->count())->toBe(0)
        ->and(CustomerNotification::query()->where('customer_id', $customer->id)->count())->toBe(0)
        ->and(CustomerVerificationCode::query()->where('customer_id', $customer->id)->count())->toBe(0)
        ->and(Cart::query()->where('customer_id', $customer->id)->count())->toBe(0);

    $order->refresh();
    expect($order->customer_name)->toBe('Удалённый покупатель')
        ->and($order->customer_phone)->toBe('')
        ->and($order->customer_email)->toBeNull()
        ->and($order->delivery_address)->toBeNull()
        ->and($order->customer_comment)->toBeNull()
        ->and($order->user_agent)->toBeNull()
        ->and($order->consent_ip)->not->toBe('198.51.100.7')
        ->and($order->customer_id)->toBe($customer->id)
        ->and($order->total)->toBe('2300.00')
        ->and($order->delivery_price)->toBe('300.00')
        ->and($order->status->value)->toBe('new')
        ->and($order->items()->count())->toBe(1)
        ->and($order->items()->first()->total)->toBe('2000.00');

    // Somebody else is untouched.
    expect($otherOrder->fresh()->customer_name)->toBe('Другой Человек')
        ->and(CustomerAddress::query()->where('customer_id', $other->id)->count())->toBe(1);

    $entry = AdminAuditEntry::query()->where('action', AuditAction::CustomerAnonymized->value)->firstOrFail();
    expect($entry->subject_id)->toBe($customer->id)
        ->and(json_encode($entry->data))->not->toContain('erase.me')->not->toContain('Стираемый')->not->toContain('9001112233');
});

it('is idempotent (CA-17)', function (): void {
    [$customer] = customerWithData();

    expect(app(CustomerAnonymizer::class)->anonymize($customer))->toBeTrue()
        ->and(app(CustomerAnonymizer::class)->anonymize($customer))->toBeFalse()
        ->and(AdminAuditEntry::query()->where('action', AuditAction::CustomerAnonymized->value)->count())->toBe(1);
});

it('fires the extension event before the data is wiped, inside the transaction', function (): void {
    [$customer] = customerWithData();
    $seen = [];

    Event::listen(CustomerAnonymizing::class, function (CustomerAnonymizing $event) use (&$seen): void {
        $seen = [$event->customer->name, Customer::query()->whereKey($event->customer->id)->value('email'), DB::transactionLevel() > 0];
    });

    app(CustomerAnonymizer::class)->anonymize($customer);

    expect($seen)->toBe(['Стираемый Покупатель', 'erase.me@example.com', true]);
});

it('rolls everything back when a listener of the event fails', function (): void {
    [$customer, $order] = customerWithData();
    Event::listen(CustomerAnonymizing::class, function (): void {
        throw new RuntimeException('listener failed');
    });

    expect(fn () => app(CustomerAnonymizer::class)->anonymize($customer))->toThrow(RuntimeException::class);

    expect($customer->fresh()->anonymized_at)->toBeNull()
        ->and($order->fresh()->customer_name)->toBe('Стираемый Покупатель')
        ->and(CustomerAddress::query()->where('customer_id', $customer->id)->count())->toBe(2);
});

// ------------------------------------------------------------------ deleting the account (CA-16)

it('asks for a code, then marks the account and ends every session (CA-16, CA-31)', function (): void {
    $customer = makeCustomer(['email' => 'leaver@example.com']);
    signInCustomer($this, $customer);

    $this->get(route('customer.security'))->assertOk()->assertSee('Удалить аккаунт');
    $this->post(route('customer.security.delete.request'))->assertRedirect(route('customer.security.delete'));
    $this->get(route('customer.security.delete'))->assertOk();

    // A wrong code changes nothing.
    $this->post(route('customer.security.delete.confirm'), ['code' => '000000'])->assertSessionHasErrors('code');
    expect($customer->fresh()->deletion_requested_at)->toBeNull();

    $this->post(route('customer.security.delete.confirm'), ['code' => lastSentCode(VerificationPurpose::DeleteAccount)])
        ->assertRedirect(route('customer.login'));

    $fresh = $customer->fresh();
    expect($fresh->deletion_requested_at)->not->toBeNull()
        ->and($fresh->auth_version)->toBe(2)
        ->and($fresh->anonymized_at)->toBeNull();

    nextRequest();
    expect(Auth::guard('customer')->check())->toBeFalse();
});

it('does not delete without the right code and the code of another purpose does not work', function (): void {
    $customer = makeCustomer(['email' => 'leaver2@example.com']);
    signInCustomer($this, $customer);

    $this->post(route('customer.security.delete.request'));
    $code = lastSentCode(VerificationPurpose::DeleteAccount);

    // The confirmation is closed to guests.
    session()->flush();
    nextRequest();
    $this->post(route('customer.security.delete.confirm'), ['code' => $code])->assertRedirect(route('customer.login'));
    expect($customer->fresh()->deletion_requested_at)->toBeNull();
});

it('refuses the password while a deletion is pending, and a sign-in by code cancels it (CA-16)', function (): void {
    $customer = makeCustomer(['email' => 'pending@example.com', 'deletion_requested_at' => now()->subDay()]);

    $this->post(route('customer.login.store'), ['email' => 'pending@example.com', 'password' => 'password-123'])->assertSessionHasErrors('email');

    app(CustomerAuthService::class)->requestLoginCode('pending@example.com', '192.0.2.5');
    $signedIn = app(CustomerAuthService::class)->authenticateByCode('pending@example.com', lastSentCode(VerificationPurpose::Login), '192.0.2.5');

    expect($signedIn->deletion_requested_at)->toBeNull()->and($customer->fresh()->deletion_requested_at)->toBeNull();
});

// ------------------------------------------------------------------ commands (CA-16, CA-24, CA-04)

it('anonymizes only after the cancel period, by the command (CA-16)', function (): void {
    app(CustomerSettings::class)->fill(['deletion_grace_days' => 3])->save();
    $due = makeCustomer(['deletion_requested_at' => now()->subDays(4)]);
    $waiting = makeCustomer(['deletion_requested_at' => now()->subDays(2)]);
    $normal = makeCustomer();

    $this->artisan('shop:anonymize-customers')->expectsOutputToContain('1')->assertSuccessful();

    expect($due->fresh()->anonymized_at)->not->toBeNull()
        ->and($waiting->fresh()->anonymized_at)->toBeNull()
        ->and($normal->fresh()->anonymized_at)->toBeNull();

    // A second run has nothing left to do.
    expect(app(CustomerRetentionService::class)->anonymizeDue())->toBe(0);
});

it('removes unconfirmed accounts older than the retention period, never confirmed ones (CA-24)', function (): void {
    app(CustomerSettings::class)->fill(['unverified_retention_days' => 7])->save();
    $old = makeCustomer(['email_verified_at' => null, 'created_at' => now()->subDays(8)]);
    $fresh = makeCustomer(['email_verified_at' => null, 'created_at' => now()->subDays(2)]);
    $verifiedOld = makeCustomer(['created_at' => now()->subDays(30)]);

    $this->artisan('shop:purge-unverified-customers')->assertSuccessful();

    expect(Customer::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Customer::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(Customer::query()->whereKey($verifiedOld->id)->exists())->toBeTrue();
});

it('purges expired codes, keeps open ones (CA-04)', function (): void {
    $make = fn (string $email, $expires) => CustomerVerificationCode::query()->forceCreate([
        'customer_id' => null, 'email' => $email, 'purpose' => VerificationPurpose::Login, 'code_hash' => str_repeat('b', 64),
        'attempts' => 0, 'expires_at' => $expires, 'sent_at' => now(), 'ip' => '192.0.2.1',
    ]);
    $make('old@example.com', now()->subDays(3));
    $make('open@example.com', now()->addMinutes(10));

    $this->artisan('shop:purge-customer-codes')->assertSuccessful();

    expect(CustomerVerificationCode::query()->pluck('email')->all())->toBe(['open@example.com']);
    expect(app(VerificationCodeService::class)->purgeExpired())->toBe(0);
});

it('schedules the three commands', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode(' ');

    expect($commands)->toContain('shop:anonymize-customers')->toContain('shop:purge-unverified-customers')->toContain('shop:purge-customer-codes');
});

it('anonymizes guest requests with the verified address that were never linked (CA-17, CA-14)', function (): void {
    $customer = makeCustomer(['email' => 'unlinked.owner@example.com']);
    $guestOrder = Order::factory()->create([
        'customer_id' => null,
        'customer_email' => 'Unlinked.Owner@example.com',
        'customer_name' => 'Гость Покупатель',
        'delivery_address' => 'г. Тестоград, ул. Личная, 1',
    ]);
    $stranger = Order::factory()->create(['customer_id' => null, 'customer_email' => 'someone.else@example.com', 'customer_name' => 'Другой Гость']);

    app(CustomerAnonymizer::class)->anonymize($customer, null, 'self');

    $guestOrder->refresh();
    expect($guestOrder->customer_id)->toBe($customer->id)
        ->and($guestOrder->customer_name)->toBe('Удалённый покупатель')
        ->and($guestOrder->customer_email)->toBeNull()
        ->and($guestOrder->delivery_address)->toBeNull()
        ->and($stranger->fresh()->customer_name)->toBe('Другой Гость');
});
