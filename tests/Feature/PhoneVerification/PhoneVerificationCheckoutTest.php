<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_miniapp.php';

use App\Enums\PhoneProviderFailureKind;
use App\Enums\PhoneVerificationPurpose;
use App\Enums\SalesMode;
use App\Exceptions\SalesException;
use App\Jobs\SendPhoneVerificationAlert;
use App\Livewire\Storefront\CheckoutForm;
use App\Livewire\Storefront\InquiryForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PhoneVerification;
use App\Models\TelegramAccount;
use App\Services\Access\ViewerContext;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\OrderService;
use App\Services\PhoneVerification\PhoneVerificationGate;
use App\Services\PhoneVerification\PhoneVerificationService;
use App\Settings\NotificationSettings;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PV-07 (guest checkout scenarios) and PV-13 (а, б, в, г, д, ж) on the checkout, the reservation form and the inquiry form.
 * The provider is the stub of tests/Support/phone_verification.php: no real call is made.
 */
const PVC_PHONE = '+79001234567';

beforeEach(function (): void {
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0, 'reservation_hours' => 48, 'reservation_via_cart' => true, 'reservation_max_items' => 20]);
    $this->settings = enablePhoneVerification(['guest_checkout' => 'all']);
    $this->gateway = phoneStub();
    $this->buy = sellable(['sales_mode' => SalesMode::Cart, 'stock_qty' => 10, 'base_price' => '1000.00']);
});

/** A checkout component of a cart; the anti-bot delay is waited out. */
function pvCheckout($cart)
{
    $component = livewireWithCart(CheckoutForm::class, $cart);
    test()->travel(5)->seconds();

    return $component;
}

/** Verifies a number in the session of the test the way a customer does: the call, then the 4 digits. */
function pvVerifyNumber(string $phone, PhoneVerificationPurpose $purpose = PhoneVerificationPurpose::GuestCheckout): void
{
    $service = app(PhoneVerificationService::class);
    $session = PhoneVerificationGate::session();
    $service->start($phone, $purpose, $session, '127.0.0.1');
    $service->verify($phone, $purpose, test()->gateway->lastCode, $session, '127.0.0.1');
}

function pvSignedIn(TestCase $test, ?Customer $customer = null): Customer
{
    $customer ??= makeCustomer();
    signInCustomer($test, $customer);
    $test->get(route('customer.orders'))->assertOk();
    nextRequest();
    request()->setLaravelSession(app('session.store'));
    app(ViewerContext::class)->beginRequest();

    return $customer;
}

describe('guest checkout «для всех обращений» (PV-07)', function (): void {
    it('calls after the form is valid, accepts the 4 digits and creates the order with phone_verified (PV-13 г)', function (): void {
        $cart = cartWith($this->buy);
        $component = fillCheckout(pvCheckout($cart))->call('submit');

        $component->assertHasNoErrors()->assertSet('pvActive', true)->assertNoRedirect();
        expect($this->gateway->types())->toBe(['flash'])
            ->and($this->gateway->calls[0]['phone'])->toBe(PVC_PHONE)
            ->and(Order::query()->count())->toBe(0);

        // The screen: the call explanation, a numeric one-time-code field, the masked number only.
        $html = $component->html();
        expect($html)->toContain('Вам звонят. Отвечать не нужно')
            ->and($html)->toContain('inputmode="numeric"')->toContain('autocomplete="one-time-code"')
            ->and($html)->not->toContain(PVC_PHONE);

        $component->set('pvCode', $this->gateway->lastCode === '0000' ? '1111' : '0000')->call('confirmPhoneCode')
            ->assertHasErrors(['pvCode'])->assertSet('pvActive', true);
        expect(Order::query()->count())->toBe(0);

        $component->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode')->assertHasNoErrors();
        $order = Order::query()->firstOrFail();

        $component->assertRedirect(route('order.thanks', $order->uuid));
        expect($order->phone_verified)->toBeTrue()->and($order->customer_phone)->toBe(PVC_PHONE)
            ->and($this->gateway->types())->toBe(['flash']);
    });

    it('works with a provider that makes its own code too (PV-02)', function (): void {
        $this->gateway = phoneStub(callerCode: false);
        $cart = cartWith($this->buy);
        $component = fillCheckout(pvCheckout($cart))->call('submit')->assertSet('pvActive', true);

        $component->set('pvCode', $this->gateway->providerCode)->call('confirmPhoneCode');

        expect(Order::query()->firstOrFail()->phone_verified)->toBeTrue();
    });

    it('does not call for an invalid form, an empty cart or a number the scenario does not allow (PV-13 б)', function (): void {
        $cart = cartWith($this->buy);
        fillCheckout(pvCheckout($cart), overrides: ['name' => ''])->call('submit')->assertHasErrors(['name'])->assertSet('pvActive', false);
        fillCheckout(pvCheckout($cart), overrides: ['consent' => false])->call('submit')->assertHasErrors(['consent']);
        // A landline passes SF-44 but the allowed prefixes are mobile only (PV-06): the field says so, nobody is called.
        fillCheckout(pvCheckout($cart), overrides: ['phone' => '8 (495) 123-45-67'])->call('submit')->assertHasErrors(['phone'])->assertSet('pvActive', false);
        // An empty cart: the request fails on the server rule and nobody is called.
        $empty = cartWith($this->buy);
        $empty->items()->delete();
        fillCheckout(pvCheckout($empty))->call('submit')->assertHasErrors(['form'])->assertSet('pvActive', false);

        expect($this->gateway->calls)->toBe([])->and(Order::query()->count())->toBe(0);
    });

    it('closes the checkout on the server without a verified number (PV-13 г)', function (): void {
        $cart = cartWith($this->buy);

        expect(fn () => app(OrderService::class)->checkoutFromCart($cart, checkoutData()))->toThrow(SalesException::class, 'Подтвердите номер телефона');
        expect(Order::query()->count())->toBe(0)->and($this->buy->fresh()->stock_qty)->toBe(10)->and($cart->items()->count())->toBe(1);
    });

    it('takes a verified number only for the same number: another number resets it (PV-13 г)', function (): void {
        $cart = cartWith($this->buy);
        pvVerifyNumber(PVC_PHONE);

        // Another number than the verified one: refused on the server, not flagged.
        expect(fn () => app(OrderService::class)->checkoutFromCart($cart, new CheckoutData(
            name: 'Иван', phone: '+7 900 765-43-21', email: null, deliveryKey: 'samovyvoz', address: null, paymentKey: 'nalicnymi-pri-polucenii',
            comment: null, consent: true, ip: '127.0.0.1',
        )))->toThrow(SalesException::class);

        // Any spelling of the verified number is the same number.
        $order = app(OrderService::class)->checkoutFromCart($cart, checkoutData())->primary();

        expect($order->phone_verified)->toBeTrue();
    });

    it('forgets the verification when the phone field changes and does not bring it back (PV-13 г)', function (): void {
        pvVerifyNumber(PVC_PHONE);
        $component = pvCheckout(cartWith($this->buy));
        $gate = app(PhoneVerificationGate::class);
        $verified = fn (): bool => $gate->isPhoneVerified(PVC_PHONE, PhoneVerificationPurpose::GuestCheckout, null, PhoneVerificationGate::session());

        expect($verified())->toBeTrue();

        $component->set('phone', '+7 900 765-43-21');
        expect($verified())->toBeFalse();

        $component->set('phone', PVC_PHONE);
        expect($verified())->toBeFalse();
    });

    it('does not call again within 24 hours for the same number and purpose (PV-07)', function (): void {
        pvVerifyNumber(PVC_PHONE);
        $cart = cartWith($this->buy);

        fillCheckout(pvCheckout($cart))->call('submit')->assertSet('pvActive', false);

        expect(Order::query()->firstOrFail()->phone_verified)->toBeTrue()->and($this->gateway->types())->toBe(['flash']);

        $this->travel(25)->hours();
        $second = cartWith(sellable(['sales_mode' => SalesMode::Cart]));
        fillCheckout(pvCheckout($second))->call('submit')->assertSet('pvActive', true);
    });

    it('lets the customer go back to change the number (PV-03)', function (): void {
        $component = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvActive', true);

        $component->call('cancelPhoneVerification')->assertSet('pvActive', false);
    });
});

describe('guest checkout «только резервы» and «только заказы от суммы» (PV-07, PV-13 д)', function (): void {
    it('lets an order-only cart through and calls for a reservation', function (): void {
        $this->settings->guest_checkout = 'reservations';
        $this->settings->save();

        $order = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvActive', false);
        expect(Order::query()->firstOrFail()->phone_verified)->toBeFalse()->and($this->gateway->calls)->toBe([]);

        $hold = mrReservable(['stock_qty' => 5]);
        fillCheckout(pvCheckout(mrCart([[$hold]])))->call('submit')->assertSet('pvActive', true);
        expect($this->gateway->types())->toBe(['flash']);
    });

    it('compares the amount with the «order» part: a big reservation does not count (MR)', function (): void {
        $this->settings->guest_checkout = 'orders_over';
        $this->settings->guest_checkout_min_amount = 500000;
        $this->settings->save();

        // 4 000 ₽ of order and 20 000 ₽ of reservation: the order part is under the threshold, no call.
        $small = mrCart([[sellable(['sales_mode' => SalesMode::Cart, 'base_price' => '4000.00'])], [mrReservable(['base_price' => '20000.00'])]]);
        $result = app(OrderService::class)->checkoutFromCart($small, mrMixedData());
        expect($result->all())->toHaveCount(2)->and($result->order->phone_verified)->toBeFalse()->and($result->reservation->phone_verified)->toBeFalse();

        // 5 000 ₽ of order (the threshold is «from»): the whole checkout needs ONE verification.
        $big = mrCart([[sellable(['sales_mode' => SalesMode::Cart, 'base_price' => '5000.00'])], [mrReservable(['base_price' => '100.00'])]]);
        expect(fn () => app(OrderService::class)->checkoutFromCart($big, mrMixedData()))->toThrow(SalesException::class);
        expect(Order::query()->count())->toBe(2);

        pvVerifyNumber(PVC_PHONE);
        $result = app(OrderService::class)->checkoutFromCart($big, mrMixedData());

        expect($result->all())->toHaveCount(2)->and($result->order->phone_verified)->toBeTrue()->and($result->reservation->phone_verified)->toBeTrue()
            ->and($this->gateway->types())->toBe(['flash']);
    });

    it('asks for the call in the form of an order of the amount and not below it', function (): void {
        $this->settings->guest_checkout = 'orders_over';
        $this->settings->guest_checkout_min_amount = 500000;
        $this->settings->save();

        fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvActive', false);
        expect(Order::query()->count())->toBe(1);

        $big = cartWith(sellable(['sales_mode' => SalesMode::Cart, 'base_price' => '6000.00']));
        fillCheckout(pvCheckout($big))->call('submit')->assertSet('pvActive', true);
        expect(Order::query()->count())->toBe(1);
    });

    it('verifies a mixed cart once under «только резервы»', function (): void {
        $this->settings->guest_checkout = 'reservations';
        $this->settings->save();
        $cart = mrCart([[$this->buy], [mrReservable()]]);

        $component = fillCheckout(pvCheckout($cart))->call('submit')->assertSet('pvActive', true);
        $component->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode');

        expect(Order::query()->count())->toBe(2)->and(Order::query()->where('phone_verified', true)->count())->toBe(2)
            ->and($this->gateway->types())->toBe(['flash']);
    });

    it('is off by default: nothing is called, nothing is flagged (PV-07)', function (): void {
        $this->settings->guest_checkout = 'off';
        $this->settings->save();

        fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit');

        expect(Order::query()->firstOrFail()->phone_verified)->toBeFalse()->and($this->gateway->calls)->toBe([]);
    });

    it('does nothing at all while the verification as a whole is off', function (): void {
        $this->settings->enabled = false;
        $this->settings->save();

        fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit');

        expect(Order::query()->count())->toBe(1)->and($this->gateway->calls)->toBe([]);
    });
});

describe('reservation and inquiry forms of a product (PV-07)', function (): void {
    it('verifies a one-product reservation under «только резервы» and flags it', function (): void {
        $this->settings->guest_checkout = 'reservations';
        $this->settings->save();
        $product = sellable(['sales_mode' => SalesMode::Reservation, 'stock_qty' => 5]);
        $component = livewireWithCart(ReservationForm::class, null, ['productId' => $product->id]);
        $this->travel(5)->seconds();
        $component->set('name', 'Иван')->set('phone', '8 (900) 123-45-67')->set('consent', true)->call('submit')
            ->assertSet('pvActive', true);
        expect(Order::query()->count())->toBe(0);

        $component->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode');

        expect(Order::query()->firstOrFail()->phone_verified)->toBeTrue()->and(Order::query()->firstOrFail()->type->value)->toBe('reservation');
    });

    it('lets an inquiry through under «только резервы» and verifies it under «для всех»', function (): void {
        $product = sellable(['sales_mode' => SalesMode::Inquiry, 'stock_qty' => 5]);
        $this->settings->guest_checkout = 'reservations';
        $this->settings->save();
        $form = livewireWithCart(InquiryForm::class, null, ['productId' => $product->id]);
        $this->travel(5)->seconds();
        $form->set('name', 'Иван')->set('phone', '8 (900) 123-45-67')->set('consent', true)->call('submit')->assertSet('pvActive', false);
        expect(Order::query()->count())->toBe(1)->and($this->gateway->calls)->toBe([]);

        $this->settings->guest_checkout = 'all';
        $this->settings->save();
        resetFormGuard();
        $component = livewireWithCart(InquiryForm::class, null, ['productId' => $product->id]);
        $this->travel(5)->seconds();
        $component->set('name', 'Иван')->set('phone', '8 (900) 123-45-67')->set('consent', true)->call('submit')->assertSet('pvActive', true);
        expect(Order::query()->count())->toBe(1);

        $component->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode');
        expect(Order::query()->count())->toBe(2)->and(Order::query()->latest('id')->first()->phone_verified)->toBeTrue();
    });
});

describe('the call, the SMS and the limits on the form (PV-03, PV-04, PV-06)', function (): void {
    it('offers the SMS only after the call and only in «звонок, а SMS — по кнопке»', function (): void {
        $this->settings->mode = 'flash_call_then_sms';
        $this->settings->save();
        $component = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvCanSms', true);

        expect($component->html())->toContain('Звонок не пришёл? Получить SMS')->and($this->gateway->types())->toBe(['flash']);

        // Too early: the pause of the call is 60 s, the message says when.
        $component->call('requestPhoneSms')->assertHasErrors(['pvCode']);
        expect($this->gateway->types())->toBe(['flash']);

        $this->travel(61)->seconds();
        $component->call('requestPhoneSms')->assertHasNoErrors()->assertSet('pvMethod', 'sms');
        expect($this->gateway->types())->toBe(['flash', 'sms']);

        $component->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode');
        expect(Order::query()->firstOrFail()->phone_verified)->toBeTrue();
    });

    it('does not show the SMS button in the flash-call-only mode', function (): void {
        $this->settings->mode = 'flash_call';
        $this->settings->save();

        fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvCanSms', false)->assertDontSee('Получить SMS');
    });

    it('shows the retry time of a throttled number (429 text)', function (): void {
        $component = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvActive', true);

        $component->call('resendPhoneCall')->assertHasErrors(['pvCode']);
        expect($component->errors()->first('pvCode'))->toContain('Повторите через');
        expect($this->gateway->types())->toBe(['flash']);
    });

    it('starts a new call after the pause', function (): void {
        $component = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit');
        $this->travel(61)->seconds();

        $component->call('resendPhoneCall')->assertHasNoErrors();

        expect($this->gateway->types())->toBe(['flash', 'flash']);
    });

    it('blocks the form for five wrong entries and wants a new call (scenario 29)', function (): void {
        $component = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit');
        $wrong = $this->gateway->lastCode === '1111' ? '2222' : '1111';

        foreach (range(1, 5) as $i) {
            $component->set('pvCode', $wrong)->call('confirmPhoneCode')->assertHasErrors(['pvCode']);
        }

        // The code is spent: even the right digits do not help now.
        $component->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode')->assertHasErrors(['pvCode']);
        expect(Order::query()->count())->toBe(0);

        $this->travel(61)->seconds();
        $component->call('resendPhoneCall');
        $component->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode');

        expect(Order::query()->firstOrFail()->phone_verified)->toBeTrue();
    });
});

describe('provider failures (PV-05, PV-13 г)', function (): void {
    it('skips the verification on a provider failure and creates the order with phone_verified = false', function (): void {
        Queue::fake();
        $notifications = app(NotificationSettings::class);
        $notifications->notify_emails = ['owner@example.com'];
        $notifications->notify_shop_email = true;
        $notifications->notify_shop_telegram = false;
        $notifications->save();
        $this->gateway->failWith(PhoneProviderFailureKind::Auth);

        $component = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertHasNoErrors();
        $order = Order::query()->firstOrFail();

        $component->assertRedirect(route('order.thanks', $order->uuid));
        expect($order->phone_verified)->toBeFalse()
            ->and(PhoneVerification::query()->firstOrFail()->note)->toBe('auth');
        Queue::assertPushed(SendPhoneVerificationAlert::class, 1);
    });

    it('uses the skip mark up with the first request (single use, PV-05)', function (): void {
        $gate = app(PhoneVerificationGate::class);
        $session = PhoneVerificationGate::session();
        $gate->markSkipped('+79001234567', PhoneVerificationPurpose::GuestCheckout, $session);

        expect($gate->assertForRequest('+79001234567', null, $session, true, false, false, 10_000_000))->toBeFalse()
            ->and($gate->wasSkipped('+79001234567', PhoneVerificationPurpose::GuestCheckout, $session))->toBeFalse();

        expect(fn () => $gate->assertForRequest('+79001234567', null, $session, true, false, false, 10_000_000))->toThrow(SalesException::class);
    });

    it('does not let the skip mark of one number cover another one', function (): void {
        $this->gateway->failWith(PhoneProviderFailureKind::Server);
        $cart = cartWith($this->buy);
        fillCheckout(pvCheckout($cart))->call('submit');

        // The mark belongs to the number of the form, not to the session.
        expect(app(PhoneVerificationGate::class)->wasSkipped('+79007654321', PhoneVerificationPurpose::GuestCheckout, PhoneVerificationGate::session()))->toBeFalse();
    });

    it('blocks the checkout on a provider failure when the owner chose «блокировать»', function (): void {
        $this->settings->on_provider_failure = 'block';
        $this->settings->save();
        $this->gateway->failWith(PhoneProviderFailureKind::Timeout);

        $component = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertHasErrors(['phone'])->assertSet('pvActive', false);

        expect(Order::query()->count())->toBe(0);
    });

    it('blocks (never skips) at the daily limit and on a zero balance', function (): void {
        $this->settings->daily_limit = 0;
        $this->settings->on_daily_limit = 'block';
        $this->settings->save();

        fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertHasErrors(['phone']);
        expect(Order::query()->count())->toBe(0)->and($this->gateway->calls)->toBe([]);

        $this->settings->daily_limit = 100;
        $this->settings->save();
        $this->gateway->refuseWithBalance();
        resetFormGuard();
        fillCheckout(pvCheckout(cartWith(sellable(['sales_mode' => SalesMode::Cart]))), overrides: ['phone' => '8 (900) 111-22-33'])->call('submit')->assertHasErrors(['phone']);

        expect(Order::query()->count())->toBe(0);
    });
});

describe('Telegram number and signed-in customers (TG-09, PV-13 а, в)', function (): void {
    it('counts the verified phone of the Mini App account as verified without a call', function (): void {
        $account = TelegramAccount::factory()->create(['phone' => PVC_PHONE, 'phone_verified_at' => now()]);
        $this->withSession(tgSession($account));

        fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvActive', false);

        expect(Order::query()->firstOrFail()->phone_verified)->toBeTrue()->and($this->gateway->calls)->toBe([]);
    });

    it('calls for another number than the one Telegram verified', function (): void {
        $account = TelegramAccount::factory()->create(['phone' => '+79007654321', 'phone_verified_at' => now()]);
        $this->withSession(tgSession($account));

        fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvActive', true);

        expect($this->gateway->types())->toBe(['flash']);
    });

    it('does not trust a phone of a Telegram account that Telegram has not verified', function (): void {
        $account = TelegramAccount::factory()->create(['phone' => PVC_PHONE, 'phone_verified_at' => null]);
        $this->withSession(tgSession($account));

        fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit')->assertSet('pvActive', true);
    });

    it('verifies without a call a signed-in customer whose form phone is the verified profile phone (PV-13 в)', function (): void {
        $customer = pvSignedIn($this, makeCustomer(['phone' => PVC_PHONE, 'phone_verified_at' => now()]));
        $cart = cartWith($this->buy);

        $order = app(OrderService::class)->checkoutFromCart($cart, checkoutData())->primary();

        expect($order->customer_id)->toBe($customer->id)->and($order->phone_verified)->toBeTrue()->and($this->gateway->calls)->toBe([]);
    });

    it('does not flag another number of a verified customer, and does not need a call for it either (the guest scenarios are for guests)', function (): void {
        pvSignedIn($this, makeCustomer(['phone' => '+79007654321', 'phone_verified_at' => now()]));

        $order = app(OrderService::class)->checkoutFromCart(cartWith($this->buy), checkoutData())->primary();

        expect($order->phone_verified)->toBeFalse()->and($this->gateway->calls)->toBe([]);
    });

    it('locks the requests of an unverified account when the registration scenario is «обязательно» (PV-13 а)', function (): void {
        $this->settings->registration = 'required';
        $this->settings->guest_checkout = 'off';
        $this->settings->save();
        pvSignedIn($this, makeCustomer(['phone' => PVC_PHONE, 'phone_verified_at' => null]));

        expect(fn () => app(OrderService::class)->checkoutFromCart(cartWith($this->buy), checkoutData()))->toThrow(SalesException::class, 'Подтвердите телефон в личном кабинете');
        expect(fn () => app(OrderService::class)->createInquiry(sellable(['sales_mode' => SalesMode::Inquiry]), inquiryData()))->toThrow(SalesException::class);
        expect(fn () => app(OrderService::class)->createReservation(sellable(['sales_mode' => SalesMode::Reservation]), reservationData()))->toThrow(SalesException::class);
        expect(Order::query()->count())->toBe(0);
    });

    it('opens the account after the phone is verified, and never locks it in «можно позже»', function (): void {
        $this->settings->registration = 'required';
        $this->settings->guest_checkout = 'off';
        $this->settings->save();
        pvSignedIn($this, makeCustomer(['phone' => PVC_PHONE, 'phone_verified_at' => now()]));

        expect(app(OrderService::class)->checkoutFromCart(cartWith($this->buy), checkoutData())->primary()->customer_id)->not->toBeNull();

        $this->settings->registration = 'later';
        $this->settings->save();
        pvSignedIn($this, makeCustomer(['phone' => PVC_PHONE, 'phone_verified_at' => null]));

        expect(app(OrderService::class)->checkoutFromCart(cartWith(sellable(['sales_mode' => SalesMode::Cart])), checkoutData())->primary()->phone_verified)->toBeFalse();
    });

    it('shows the unverified account a link to the verification on the checkout form', function (): void {
        $this->settings->registration = 'required';
        $this->settings->save();
        $customer = pvSignedIn($this, makeCustomer(['phone' => PVC_PHONE, 'phone_verified_at' => null]));
        $cart = Cart::factory()->create(['customer_id' => $customer->id]);
        CartItem::factory()->forProduct($this->buy, 1)->create(['cart_id' => $cart->id]);

        $this->get(route('checkout'))->assertOk()->assertSee('data-phone-locked', false)->assertSee(route('customer.phone'), false);
    });
});

describe('answers do not reveal the shop database (PV-13 ж)', function (): void {
    it('gives the same screen and the same errors for a number of an account and for a new one', function (): void {
        makeCustomer(['phone' => PVC_PHONE, 'phone_verified_at' => now()]);
        $known = fillCheckout(pvCheckout(cartWith($this->buy)))->call('submit');
        $knownHtml = $known->html();
        $known->set('pvCode', $this->gateway->lastCode === '0000' ? '1111' : '0000')->call('confirmPhoneCode');
        $knownError = $known->errors()->first('pvCode');

        resetFormGuard();
        $fresh = fillCheckout(pvCheckout(cartWith(sellable(['sales_mode' => SalesMode::Cart]))), overrides: ['phone' => '8 (900) 765-43-21'])->call('submit');
        $freshError = $fresh->set('pvCode', $this->gateway->lastCode === '0000' ? '1111' : '0000')->call('confirmPhoneCode')->errors()->first('pvCode');

        expect($freshError)->toBe($knownError)
            ->and(preg_replace('/\*+\d{4}/', '', $fresh->html()))->toContain('Вам звонят')
            ->and($knownHtml)->toContain('Вам звонят');
    });

    it('has no endpoint that calls an arbitrary number (PV-13 б)', function (): void {
        $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => $route->uri())->filter(fn (string $uri): bool => str_contains($uri, 'phone'));

        // The only phone route is the signed-in page of the cabinet, the call goes through the validated forms.
        expect($routes->values()->all())->toContain('account/phone')->not->toContain('phone/verify')->not->toContain('api/phone');
        $this->postJson('/phone/verify', ['phone' => PVC_PHONE])->assertNotFound();
        $this->postJson('/account/phone', ['phone' => PVC_PHONE])->assertStatus(405);
        expect($this->gateway->calls)->toBe([]);
    });
});
