<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/telegram_miniapp.php';

use App\Enums\CustomerNotificationType;
use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\TelegramAccount;
use App\Support\TelegramMode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * TM-04: sign in with the code and link, registration with the link, sign-out and unlink, the old link of another
 * customer, nothing of it on the site.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    connectTelegramBot(['miniapp_enabled' => true]);
    $this->customer = makeCustomer(['email' => 'mini.owner@example.com']);
    $this->account = TelegramAccount::factory()->create(['telegram_user_id' => 777, 'first_name' => 'Иван', 'last_name' => 'Петров', 'phone' => '+79001234567', 'phone_verified_at' => now(), 'customer_id' => null]);
});

function tgCodeLogin(string $email): TestResponse
{
    test()->post(route('customer.login.send-code'), ['email' => $email]);

    return test()->post(route('customer.login.verify'), ['code' => lastSentCode(VerificationPurpose::Login)]);
}

it('signs in with the e-mail code and links the Telegram to the customer (TM-04 b)', function (): void {
    $this->withSession(tgSession($this->account));

    tgCodeLogin('mini.owner@example.com')->assertRedirect(route('account'));

    $this->assertAuthenticatedAs($this->customer, 'customer');
    expect($this->account->fresh()->customer_id)->toBe($this->customer->id)
        ->and($this->account->fresh()->customer_auth_version)->toBe($this->customer->auth_version)
        // The Telegram mode survives the session regeneration of the sign-in.
        ->and(session(TelegramMode::SESSION_KEY))->toBeTrue()
        ->and(session(TelegramMode::ACCOUNT_KEY))->toBe($this->account->id);
});

it('links nothing on the site, outside the Mini App (TM-04)', function (): void {
    tgCodeLogin('mini.owner@example.com')->assertRedirect(route('account'));

    expect($this->account->fresh()->customer_id)->toBeNull();
});

it('shows only the code sign-in in the Mini App (TM-04 b)', function (): void {
    $this->withSession(tgSession($this->account))->get(route('customer.login'))
        ->assertOk()->assertSee('data-tg-login-note', false)->assertDontSee('Вход по паролю');

    $this->flushSession();
    $this->get(route('customer.login'))->assertSee('Вход по паролю');
});

it('moves the link of another customer only after the code of the new account, and tells the old one (TM-04 b)', function (): void {
    $old = makeCustomer(['email' => 'old.owner@example.com']);
    $this->account->forceFill(['customer_id' => $old->id, 'customer_auth_version' => $old->auth_version])->save();
    $this->withSession(tgSession($this->account));

    // Asking for a code and entering a wrong one changes nothing.
    $this->post(route('customer.login.send-code'), ['email' => 'mini.owner@example.com']);
    $this->post(route('customer.login.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
    expect($this->account->fresh()->customer_id)->toBe($old->id);

    $this->post(route('customer.login.verify'), ['code' => lastSentCode(VerificationPurpose::Login)])->assertRedirect(route('account'));

    expect($this->account->fresh()->customer_id)->toBe($this->customer->id);

    $notice = CustomerNotification::query()->where('customer_id', $old->id)->firstOrFail();
    expect($notice->type)->toBe(CustomerNotificationType::TelegramUnlinked->value)
        ->and($notice->title)->toBe('Telegram отключён от аккаунта');
});

it('frees the earlier Telegram of a customer who links another one (unique customer_id)', function (): void {
    $other = TelegramAccount::factory()->create(['telegram_user_id' => 888, 'customer_id' => $this->customer->id, 'customer_auth_version' => $this->customer->auth_version]);
    $this->withSession(tgSession($this->account));

    tgCodeLogin('mini.owner@example.com');

    expect($this->account->fresh()->customer_id)->toBe($this->customer->id)
        ->and($other->fresh()->customer_id)->toBeNull();
});

it('prefills the registration with the Telegram name and the verified phone and links after the confirmation (TM-04 c)', function (): void {
    $this->withSession(tgSession($this->account))
        ->get(route('customer.register'))
        ->assertOk()
        ->assertSee('value="Иван Петров"', false)
        ->assertSee('value="+79001234567"', false);

    $this->post(route('customer.register.store'), registrationBody(['email' => 'new.buyer@example.com']))->assertRedirect(route('customer.confirm'));
    $this->post(route('customer.confirm.store'), ['code' => lastSentCode(VerificationPurpose::Register)])->assertRedirect(route('account'));

    $customer = Customer::query()->where('email', 'new.buyer@example.com')->firstOrFail();
    expect($this->account->fresh()->customer_id)->toBe($customer->id);
});

it('does not prefill an unverified phone (TM-04 c, TG-09)', function (): void {
    $this->account->forceFill(['phone_verified_at' => null])->save();

    $this->withSession(tgSession($this->account))->get(route('customer.register'))
        ->assertSee('value="Иван Петров"', false)->assertDontSee('value="+79001234567"', false);
});

it('signs out in the Mini App, unlinks the Telegram and keeps the Telegram mode (TM-04 d)', function (): void {
    $this->account->forceFill(['customer_id' => $this->customer->id, 'customer_auth_version' => $this->customer->auth_version])->save();
    signInCustomer($this, $this->customer);
    $this->withSession(tgSession($this->account) + [TelegramMode::LOGIN_KEY => true]);

    $this->get(route('account'))->assertOk()->assertSee('data-tg-logout-warning', false)->assertSee('data-tg-about', false)->assertSee('data-tg-open-site', false);

    $this->post(route('customer.logout'))->assertRedirect(route('home'));

    $this->assertGuest('customer');
    expect($this->account->fresh()->customer_id)->toBeNull()
        ->and($this->account->fresh()->customer_auth_version)->toBeNull()
        ->and(session(TelegramMode::SESSION_KEY))->toBeTrue()
        ->and(session(TelegramMode::ACCOUNT_KEY))->toBe($this->account->id)
        ->and(session(TelegramMode::LOGIN_KEY))->toBeNull();

    // The next entry does not sign in again.
    $this->postJson('/tg/session', ['initData' => tgInitData(user: ['id' => 777, 'first_name' => 'Иван'])])->assertOk();
    expect(session(Auth::guard('customer')->getName()))->toBeNull();
});

it('does not touch the Telegram link when a customer signs out on the site (TM-04)', function (): void {
    $this->account->forceFill(['customer_id' => $this->customer->id, 'customer_auth_version' => $this->customer->auth_version])->save();
    signInCustomer($this, $this->customer);

    $this->post(route('customer.logout'))->assertRedirect(route('home'));

    expect($this->account->fresh()->customer_id)->toBe($this->customer->id);
});

it('keeps a password sign-in when the Telegram mode is left (TM-03)', function (): void {
    signInCustomer($this, $this->customer);
    $this->withSession(tgSession($this->account));

    $this->postJson('/tg/leave')->assertOk();

    expect(session(Auth::guard('customer')->getName()))->toBe($this->customer->id);
});

it('drops a Telegram sign-in when the Telegram mode is left (TM-03)', function (): void {
    signInCustomer($this, $this->customer);
    $this->withSession(tgSession($this->account) + [TelegramMode::LOGIN_KEY => true]);

    $this->postJson('/tg/leave')->assertOk();

    expect(session(Auth::guard('customer')->getName()))->toBeNull();
});

it('merges the Telegram cart into the cart of the customer who signs in (TM-10, CA-15)', function (): void {
    $cart = cartWith(sellable());
    $cart->forceFill(['telegram_account_id' => $this->account->id])->save();
    $this->withSession(tgSession($this->account));

    tgCodeLogin('mini.owner@example.com')->assertRedirect(route('account'));

    expect($cart->fresh()->customer_id)->toBe($this->customer->id);
});
