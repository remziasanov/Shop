<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/telegram_miniapp.php';

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\Telegram\TelegramLinkService;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use App\Support\TelegramMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Review fixes of stage 17: the Telegram sign-in ends with the link (TM-04 г), `check` only from the signed start_param
 * (TG-03), the SQL budget with a guest cart (TM-13), the proxy-aware HTTPS detection (TM-03), separate throttle buckets.
 */
beforeEach(function (): void {
    $this->withoutVite();
    connectTelegramBot(['miniapp_enabled' => true, 'autologin_enabled' => true]);
});

function tgSignedInLinked(): array
{
    $customer = makeCustomer();
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 777, 'customer_id' => $customer->id, 'customer_auth_version' => $customer->auth_version]);

    test()->postJson('/tg/session', ['initData' => tgInitData()])->assertOk();
    expect(session(Auth::guard('customer')->getName()))->toBe($customer->id)->and(session(TelegramMode::LOGIN_KEY))->toBeTrue();
    nextRequest();

    return [$customer, $account];
}

function expectTelegramGuest(): void
{
    expect(session(Auth::guard('customer')->getName()))->toBeNull()
        ->and(session(TelegramMode::LOGIN_KEY))->toBeNull()
        ->and(session(TelegramMode::SESSION_KEY))->toBeTrue();
}

it('signs the Mini App out on the next request after «Отключить Telegram» on the site (TM-04 г)', function (): void {
    [$customer] = tgSignedInLinked();

    // The owner presses «Отключить Telegram» in the cabinet (another browser).
    app(TelegramLinkService::class)->unlinkCustomer($customer);

    $this->get('/account')->assertRedirect();
    expectTelegramGuest();

    // Reopening the Mini App does not sign in again.
    nextRequest();
    $this->postJson('/tg/session', ['initData' => tgInitData()])->assertOk();
    expectTelegramGuest();
});

it('signs the Mini App out on the next request when the auto-login is switched off (TM-04 г)', function (): void {
    tgSignedInLinked();

    connectTelegramBot(['autologin_enabled' => false]);

    $this->get('/account')->assertRedirect();
    expectTelegramGuest();
});

it('drops an earlier Telegram sign-in when the Mini App is reopened with the auto-login off (TM-04 г)', function (): void {
    tgSignedInLinked();
    connectTelegramBot(['autologin_enabled' => false]);

    $this->postJson('/tg/session', ['initData' => tgInitData()])->assertOk();

    expectTelegramGuest();
});

it('signs the Mini App out when the auth version of the customer changed (TM-04 г, CA-31)', function (): void {
    [$customer] = tgSignedInLinked();

    $customer->bumpAuthVersion();

    $this->get('/account')->assertRedirect();
    expectTelegramGuest();
});

it('signs the Mini App out when the Telegram is linked to another customer meanwhile (TM-04 г)', function (): void {
    [, $account] = tgSignedInLinked();
    $other = makeCustomer();

    $account->forceFill(['customer_id' => $other->id, 'customer_auth_version' => $other->auth_version])->save();

    $this->get('/account')->assertRedirect();
    expect(session(Auth::guard('customer')->getName()))->toBeNull();
});

it('keeps a Telegram sign-in while the link stands, and a password sign-in that never came from Telegram', function (): void {
    [$customer] = tgSignedInLinked();

    $this->get('/account')->assertOk();
    expect(session(Auth::guard('customer')->getName()))->toBe($customer->id);

    // A password sign-in of the browser with an unlinked Telegram has no Telegram flag and is not revalidated.
    $this->flushSession();
    nextRequest();
    $other = makeCustomer();
    signInCustomer($this, $other);
    $this->withSession(tgSession(TelegramAccount::factory()->create(['telegram_user_id' => 778])));

    $this->get('/account')->assertOk();
    expect(session(Auth::guard('customer')->getName()))->toBe($other->id);
});

it('asks one extra indexed query per request of a Telegram sign-in (TM-04 г, NF-02)', function (): void {
    tgSignedInLinked();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/tg/about')->assertOk();
    $withLogin = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'telegram_accounts'))->count();
    DB::disableQueryLog();

    expect($withLogin)->toBe(1);
});

it('does not set the startapp mark from the unsigned to=check or start_param=check hint (TG-03)', function (): void {
    $this->postJson('/tg/session', ['initData' => tgInitData(), 'to' => 'check'])->assertOk()->assertJson(['url' => '/tg/start']);
    $this->get('/tg/start')->assertRedirect(route('home'));

    app()->forgetInstance(TelegramSettings::class);
    expect(app(TelegramSettings::class)->startapp_verified_at)->toBeNull();
});

it('sets the startapp mark only from the signed start_param=check (TG-03)', function (): void {
    $this->postJson('/tg/session', ['initData' => tgInitData(['start_param' => 'check'])])->assertOk()->assertJson(['url' => '/tg/start']);
    $this->get('/tg/start')->assertRedirect(route('home'));

    app()->forgetInstance(TelegramSettings::class);
    expect(app(TelegramSettings::class)->startapp_verified_at)->not->toBeNull();
});

it('runs at most 5 SQL statements for POST /tg/session of a linked customer with a guest Telegram cart, and merges on /tg/start (TM-13, CA-15)', function (): void {
    $customer = makeCustomer();
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 777, 'customer_id' => $customer->id, 'customer_auth_version' => $customer->auth_version]);
    $cart = Cart::factory()->create(['telegram_account_id' => $account->id]);
    CartItem::factory()->count(2)->create(['cart_id' => $cart->id]);

    foreach ([TelegramSettings::class, GeneralSettings::class] as $settings) {
        app()->forgetInstance($settings);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->postJson('/tg/session', ['initData' => tgInitData()])->assertOk()->assertJson(['url' => '/tg/start']);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(count($queries))->toBeLessThanOrEqual(5, implode("\n", $queries))
        ->and($cart->fresh()->customer_id)->toBeNull();

    nextRequest();
    $this->get('/tg/start')->assertRedirect(route('home'));

    expect($cart->fresh()->customer_id)->toBe($customer->id);
});

it('gives /tg SameSite=None cookies behind a trusted proxy that forwards X-Forwarded-Proto: https (TM-03)', function (): void {
    config(['trustedproxy.proxies' => '*']);

    $response = $this->withHeader('X-Forwarded-Proto', 'https')->get('http://localhost/tg')->assertOk();
    $cookies = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie;
    }

    foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $name) {
        expect($cookies[$name]->getSameSite())->toBe('none', $name)
            ->and($cookies[$name]->isSecure())->toBeTrue($name)
            ->and($cookies[$name]->isPartitioned())->toBeTrue($name);
    }
});

it('ignores X-Forwarded-Proto while no proxy is trusted (default) (TM-03)', function (): void {
    config(['trustedproxy.proxies' => null]);

    $response = $this->withHeader('X-Forwarded-Proto', 'https')->get('http://localhost/tg')->assertOk();

    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getSameSite())->toBe('lax', $cookie->getName());
    }
});

it('keeps a separate throttle bucket for every /tg endpoint (TM-13)', function (): void {
    $body = ['initData' => 'x'];

    // 20 write-access calls use up only their own bucket.
    foreach (range(1, 20) as $_) {
        $this->postJson('/tg/write-access', ['allowed' => false])->assertStatus(403);
    }

    $this->postJson('/tg/write-access', ['allowed' => false])->assertStatus(429);
    $this->postJson('/tg/session', $body)->assertForbidden();
    $this->get('/tg/phone')->assertOk();
    $this->postJson('/tg/leave')->assertOk();
    $this->get('/tg/start')->assertRedirect();
});

it('throttles POST /tg/session on its own cap (TM-13)', function (): void {
    foreach (range(1, 60) as $_) {
        $this->postJson('/tg/session', ['initData' => 'x'])->assertForbidden();
    }

    $this->postJson('/tg/session', ['initData' => 'x'])->assertStatus(429);
});

it('keeps the Mini App session after a password change and shows the status (CA-08, TM-04 а)', function (): void {
    $customer = makeCustomer();
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 777, 'customer_id' => $customer->id, 'customer_auth_version' => $customer->auth_version]);
    $this->postJson('/tg/session', ['initData' => tgInitData()])->assertOk();
    nextRequest();

    $this->post(route('customer.profile.password'), ['current_password' => 'password-123', 'password' => 'N3w-Strong-pass-77', 'password_confirmation' => 'N3w-Strong-pass-77'])
        ->assertRedirect()
        ->assertSessionHas('status', __('account.profile.password_changed'));
    nextRequest();

    $this->get(route('customer.profile'))->assertOk()->assertSee(__('account.profile.password_changed'));
    expect(session(TelegramMode::LOGIN_KEY))->toBeNull();

    // The stale link ends on the next Mini App open: the version no longer matches (TM-04 а), the session stays a guest.
    $this->postJson('/tg/session', ['initData' => tgInitData()])->assertOk();
    expect($account->fresh()->customer_id)->toBeNull();
});

it('does not drop another customer who signs in by password after a staff session logout in the Mini App', function (): void {
    $staff = User::factory()->admin()->create();
    signInStaff($this, $staff);
    $a = makeCustomer();
    TelegramAccount::factory()->create(['telegram_user_id' => 777, 'customer_id' => $a->id, 'customer_auth_version' => $a->auth_version]);
    $this->postJson('/tg/session', ['initData' => tgInitData()])->assertOk();
    nextRequest();
    $this->post(route('customer.logout'))->assertRedirect();
    nextRequest();

    $b = makeCustomer();
    $this->post(route('customer.login.store'), ['email' => $b->email, 'password' => 'password-123'])->assertRedirect();
    nextRequest();
    $this->get(route('customer.profile'))->assertOk();
    nextRequest();

    expect(Auth::guard('customer')->id())->toBe($b->id);
});

it('trusts only X-Forwarded-Proto: a spoofed X-Forwarded-For / -Host changes neither ip() nor the host, even with * (TM-03, NF-12)', function (): void {
    Route::get('/__probe_proxy', fn (Request $r): array => ['ip' => $r->ip(), 'secure' => $r->isSecure(), 'host' => $r->getHost()]);
    config(['trustedproxy.proxies' => '*']);

    $spoofed = $this->withHeaders(['X-Forwarded-For' => '203.0.113.7, 198.51.100.9', 'X-Forwarded-Host' => 'evil.example'])->getJson('http://localhost/__probe_proxy')->assertOk()->json();
    expect($spoofed['ip'])->toBe('127.0.0.1')->and($spoofed['host'])->not->toBe('evil.example');

    $https = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])->getJson('http://localhost/__probe_proxy')->assertOk()->json();
    expect($https['secure'])->toBeTrue()->and($https['ip'])->toBe('127.0.0.1');
});
