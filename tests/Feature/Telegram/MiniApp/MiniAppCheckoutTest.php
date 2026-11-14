<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/telegram_miniapp.php';

use App\Enums\OrderChannel;
use App\Exceptions\FormGuardException;
use App\Livewire\Storefront\CheckoutForm;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Services\Cart\CartMerger;
use App\Services\Cart\CartService;
use App\Services\Sales\FormGuard;
use App\Support\TelegramMode;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * TM-05, TM-10, TM-14: checkout from the Mini App, the Telegram account on the request and the cart, the extra limit.
 */
beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    connectTelegramBot(['miniapp_enabled' => true]);
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
    $this->account = TelegramAccount::factory()->create(['telegram_user_id' => 777, 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => null, 'phone_verified_at' => null, 'chat_started_at' => null]);
    session(tgSession($this->account));
});

it('puts channel telegram and the Telegram account on the order of a guest (TM-05, TM-14)', function (): void {
    $cart = cartWith(sellable());
    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();

    // The client may only claim `pwa`; inside the Mini App the server decides.
    fillCheckout($component, overrides: ['name' => 'Иван Петров'])->set('channel', 'pwa')->call('submit')->assertHasNoErrors();

    $order = Order::query()->firstOrFail();

    expect($order->channel)->toBe(OrderChannel::Telegram)
        ->and($order->telegram_account_id)->toBe($this->account->id)
        ->and($order->customer_id)->toBeNull();
});

it('keeps channel web and no Telegram account outside the Mini App (TM-14)', function (): void {
    session()->flush();
    $cart = cartWith(sellable());
    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();

    fillCheckout($component)->set('channel', 'telegram')->call('submit')->assertHasNoErrors();

    $order = Order::query()->firstOrFail();

    expect($order->channel)->toBe(OrderChannel::Web)->and($order->telegram_account_id)->toBeNull();
});

it('prefills the name from Telegram and the verified phone, both editable (TM-05)', function (): void {
    cartWith(sellable());

    Livewire::test(CheckoutForm::class)->assertSet('name', 'Иван Петров')->assertSet('phone', '');

    $this->account->forceFill(['phone' => '+79001234567', 'phone_verified_at' => now()])->save();

    Livewire::test(CheckoutForm::class)
        ->assertSet('name', 'Иван Петров')
        ->assertSet('phone', '+79001234567')
        ->set('name', 'Другое имя')->assertSet('name', 'Другое имя');
});

it('does not use an unverified phone of the account (TM-05, TG-09)', function (): void {
    $this->account->forceFill(['phone' => '+79001234567', 'phone_verified_at' => null])->save();

    Livewire::test(CheckoutForm::class)->assertSet('phone', '');
});

it('limits the submits per Telegram user to 5 a minute besides the IP limit (TM-05, SF-48)', function (): void {
    $guard = new FormGuard;
    RateLimiter::clear('sales-form:tg:777');

    foreach (range(1, 5) as $i) {
        $token = $guard->issueToken();
        $this->travel(4)->seconds();
        // A different IP each time: only the Telegram user is the same.
        $guard->check('', $token, '10.0.1.'.$i, 777);
    }

    $token = $guard->issueToken();
    $this->travel(4)->seconds();

    expect(fn () => $guard->check('', $token, '10.0.1.99', 777))->toThrow(FormGuardException::class);
    $guard->check('', $token, '10.0.1.99', 778);
});

it('asks for the write access of the bot only while the chat is not started (TM-05)', function (): void {
    $cart = cartWith(sellable());
    $this->withCookie('cart', $cart->token);

    $this->withSession(tgSession($this->account))->get(route('checkout'))->assertOk()->assertSee('data-tg-checkout', false)->assertSee('data-tg-write', false);

    $this->withSession(tgSession($this->account) + [TelegramMode::CHAT_KEY => true])->get(route('checkout'))
        ->assertOk()->assertSee('data-tg-checkout', false)->assertDontSee('data-tg-write', false);
});

it('records a granted write access and tells the thanks page when there is none (TM-05)', function (): void {
    $this->withSession(tgSession($this->account))->postJson('/tg/write-access', ['allowed' => false])->assertOk();
    expect($this->account->fresh()->chat_started_at)->toBeNull();

    $order = Order::factory()->create(['telegram_account_id' => $this->account->id]);
    $this->withSession(tgSession($this->account))->get(route('order.thanks', $order->uuid))->assertOk()->assertSee('data-tg-no-notifications', false);

    $this->withSession(tgSession($this->account))->postJson('/tg/write-access', ['allowed' => true])->assertOk();

    expect($this->account->fresh()->chat_started_at)->not->toBeNull();
    $this->withSession(tgSession($this->account) + [TelegramMode::CHAT_KEY => true])->get(route('order.thanks', $order->uuid))->assertDontSee('data-tg-no-notifications', false);
});

it('answers the verified phone of the session account only (TG-09)', function (): void {
    $this->withSession(tgSession($this->account))->getJson('/tg/phone')->assertOk()->assertJson(['phone' => null]);

    $this->account->forceFill(['phone' => '+79001234567', 'phone_verified_at' => now()])->save();

    $this->withSession(tgSession($this->account))->getJson('/tg/phone')->assertJson(['phone' => '+79001234567']);

    $this->flushSession();
    $this->getJson('/tg/phone')->assertJson(['phone' => null]);
});

it('binds the guest cart to the Telegram account, across devices (TM-10)', function (): void {
    $product = sellable();
    $request = request();

    // First device: a cart is created with the cookie and the account.
    $cart = app(CartService::class)->getOrCreate($request);
    app(CartService::class)->add($cart, $product, 2);

    expect($cart->fresh()->telegram_account_id)->toBe($this->account->id);

    // Another device: no cookie at all, the same Telegram account.
    $other = Request::create('/cart');
    $found = app(CartService::class)->current($other);

    expect($found?->id)->toBe($cart->id)
        ->and(app(CartService::class)->countForRequest($other))->toBe(2);
});

it('adopts the cart of the cookie for the account (TM-10)', function (): void {
    $cart = cartWith(sellable());
    $request = Request::create('/cart', 'GET', [], ['cart' => $cart->token]);

    expect(app(CartService::class)->current($request)?->id)->toBe($cart->id)
        ->and($cart->fresh()->telegram_account_id)->toBe($this->account->id);
});

it('does not hand a cart of another Telegram account to this one (TM-10)', function (): void {
    $foreign = Cart::factory()->create(['telegram_account_id' => TelegramAccount::factory()->create()->id]);
    CartItem::factory()->forProduct(sellable())->create(['cart_id' => $foreign->id]);

    $request = Request::create('/cart', 'GET', [], ['cart' => $foreign->token]);

    expect(app(CartService::class)->current($request))->toBeNull();
});

it('merges the Telegram cart into the customer cart at sign-in (TM-10, CA-15)', function (): void {
    $customer = makeCustomer();
    $cart = Cart::factory()->create(['telegram_account_id' => $this->account->id]);
    CartItem::factory()->forProduct(sellable())->create(['cart_id' => $cart->id, 'qty' => 2]);

    app(CartMerger::class)->merge($customer, $cart->token);

    expect((int) Cart::query()->where('customer_id', $customer->id)->firstOrFail()->items()->sum('qty'))->toBe(2)
        ->and(app(CartService::class)->current(Request::create('/cart')))->toBeNull();
});
