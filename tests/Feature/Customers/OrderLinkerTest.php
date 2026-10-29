<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Events\CustomerEmailVerified;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Access\ViewerContext;
use App\Services\Customers\OrderLinker;
use App\Services\Orders\OrderService;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * CA-14 (TZ 17.3): guest requests with the same e-mail are linked once the address is confirmed.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
});

it('links guest requests by a confirmed e-mail regardless of letter case, in batches of 200', function (): void {
    $customer = makeCustomer(['email' => 'link.me@example.com']);
    Order::factory()->count(3)->create(['customer_email' => 'LINK.me@Example.com']);
    Order::factory()->create(['customer_email' => 'someone.else@example.com']);
    $taken = Order::factory()->create(['customer_email' => 'link.me@example.com', 'customer_id' => makeCustomer()->id]);

    expect(app(OrderLinker::class)->link($customer))->toBe(3);

    expect(Order::query()->where('customer_id', $customer->id)->count())->toBe(3)
        ->and($taken->fresh()->customer_id)->not->toBe($customer->id)
        ->and(Order::query()->whereNull('customer_id')->count())->toBe(1);
});

it('processes more than one batch', function (): void {
    $customer = makeCustomer(['email' => 'many.orders@example.com']);
    Order::factory()->count(OrderLinker::BATCH + 5)->create(['customer_email' => 'many.orders@example.com']);

    expect(app(OrderLinker::class)->link($customer))->toBe(OrderLinker::BATCH + 5)
        ->and(app(OrderLinker::class)->link($customer))->toBe(0);
});

it('links nothing without a confirmed e-mail', function (): void {
    $customer = makeCustomer(['email' => 'unverified@example.com', 'email_verified_at' => null]);
    Order::factory()->create(['customer_email' => 'unverified@example.com']);

    expect(app(OrderLinker::class)->link($customer))->toBe(0)
        ->and(Order::query()->whereNotNull('customer_id')->count())->toBe(0);
});

it('links after the registration code is confirmed, not before (CA-14)', function (): void {
    $order = Order::factory()->create(['customer_email' => 'new.buyer@example.com']);

    $this->post(route('customer.register.store'), registrationBody(['email' => 'new.buyer@example.com']))->assertRedirect(route('customer.confirm'));

    $customer = Customer::query()->where('email', 'new.buyer@example.com')->firstOrFail();
    expect($order->fresh()->customer_id)->toBeNull();

    $this->post(route('customer.confirm.store'), ['code' => lastSentCode(VerificationPurpose::Register)])->assertRedirect();

    expect($order->fresh()->customer_id)->toBe($customer->id);
});

it('links the requests of the new address after a confirmed e-mail change', function (): void {
    $customer = makeCustomer(['email' => 'old.address@example.com']);
    $order = Order::factory()->create(['customer_email' => 'new.address@example.com']);
    signInCustomer($this, $customer);

    $this->post(route('customer.email.request'), ['email' => 'new.address@example.com'])->assertRedirect();
    $this->post(route('customer.email.confirm.store'), ['code' => lastSentCode(VerificationPurpose::ChangeEmail)])->assertRedirect();

    expect($order->fresh()->customer_id)->toBe($customer->id);
});

it('does not touch requests while the event is only dispatched for an unconfirmed customer', function (): void {
    $customer = makeCustomer(['email' => 'late@example.com', 'email_verified_at' => null]);
    Order::factory()->create(['customer_email' => 'late@example.com']);

    CustomerEmailVerified::dispatch($customer);

    expect(Order::query()->whereNotNull('customer_id')->count())->toBe(0);
});

it('gives a request placed under an account the customer id at once (CA-14, CA-26)', function (): void {
    $customer = makeCustomer();
    $product = sellable();
    actAsCustomerForService($this, $customer);
    $order = app(OrderService::class)->createFromCart(cartWith($product), checkoutData());

    expect($order->customer_id)->toBe($customer->id)->and($order->customer_group_name)->toBe('Розница');
});

/** Makes the current request the one of a signed-in customer (OrderService is called outside HTTP). */
function actAsCustomerForService(TestCase $test, Customer $customer): void
{
    signInCustomer($test, $customer);
    nextRequest();
    request()->setLaravelSession(app('session.store'));
    app(ViewerContext::class)->beginRequest();
}

it('links guest requests placed after the confirmation at the next sign-in (CA-14)', function (): void {
    $customer = makeCustomer(['email' => 'later.buyer@example.com']);
    $order = Order::factory()->create(['customer_email' => 'Later.Buyer@example.com']);

    $this->post(route('customer.login.store'), ['email' => 'later.buyer@example.com', 'password' => 'password-123'])->assertRedirect(route('account'));

    expect($order->fresh()->customer_id)->toBe($customer->id);
});

it('does not break the sign-in when the linking fails (CA-14)', function (): void {
    // The failure is forced with a SQLite trigger.
    makeCustomer(['email' => 'broken.link@example.com']);
    Order::factory()->create(['customer_email' => 'broken.link@example.com']);
    // The batch update of the linker fails; the failure is reported and the sign-in goes on.
    DB::statement('CREATE TRIGGER fail_order_link BEFORE UPDATE ON orders BEGIN SELECT RAISE(ABORT, \'boom\'); END');

    $this->post(route('customer.login.store'), ['email' => 'broken.link@example.com', 'password' => 'password-123'])->assertRedirect(route('account'));

    $this->assertAuthenticated('customer');
    expect(Order::query()->whereNull('customer_id')->count())->toBe(1);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'forced with a SQLite trigger');

it('matches the e-mail of a request without regard to letter case on every driver (CA-14)', function (): void {
    $customer = makeCustomer(['email' => 'case.check@example.com']);
    Order::factory()->create(['customer_email' => 'CASE.Check@EXAMPLE.com']);

    expect(app(OrderLinker::class)->link($customer))->toBe(1);
});
