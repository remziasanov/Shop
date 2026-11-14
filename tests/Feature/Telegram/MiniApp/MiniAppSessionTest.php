<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/telegram_miniapp.php';

use App\Models\Customer;
use App\Models\TelegramAccount;
use App\Services\Customers\CustomerAuthService;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use App\Support\TelegramMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * TM-01, TM-02, TM-03, TM-04 (a), TM-13: the entry page, the session from a signed initData, the switch of the user,
 * the automatic sign-in and its absence, the SQL budget of POST /tg/session.
 */
beforeEach(function (): void {
    $this->withoutVite();
    connectTelegramBot(['miniapp_enabled' => true, 'autologin_enabled' => true]);
});

function tgSessionPost(string $initData, array $extra = []): TestResponse
{
    return test()->postJson('/tg/session', ['initData' => $initData] + $extra);
}

it('serves a light noindex entry page that loads the Telegram script (TM-01, TM-13)', function (): void {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/tg')->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('https://telegram.org/js/telegram-web-app.js')
        ->and($html)->toContain('noindex')
        ->and($html)->toContain('Это приложение открывается в Telegram')
        ->and($html)->toContain('Откройте магазин заново из бота')
        ->and(collect($queries)->filter(fn (string $sql): bool => str_contains($sql, 'products') || str_contains($sql, 'categories') || str_contains($sql, 'menu_items'))->all())->toBe([]);
});

it('says that the Mini App is unavailable while it is switched off (TM-01)', function (): void {
    connectTelegramBot(['miniapp_enabled' => false]);

    $response = $this->get('/tg')->assertOk();

    expect($response->getContent())->not->toContain('telegram-web-app.js');
    $response->assertSee('Магазин в Telegram временно недоступен');
    $this->postJson('/tg/session', ['initData' => tgInitData()])->assertForbidden();
});

it('is not in the sitemap and the entry is excluded from indexing (TM-01)', function (): void {
    expect(file_exists(public_path('sitemap.xml')) ? file_get_contents(public_path('sitemap.xml')) : '')->not->toContain('/tg');
    $this->get('/robots.txt')->assertOk();
});

it('opens the Telegram mode from a signed initData and creates the account (TM-02, TM-03)', function (): void {
    tgSessionPost(tgInitData(user: ['id' => 777, 'first_name' => 'Ivan', 'last_name' => 'Petrov', 'username' => 'ivan_p', 'language_code' => 'ru', 'allows_write_to_pm' => true]))
        ->assertOk()
        ->assertJson(['url' => '/'])
        ->assertSessionHas(TelegramMode::SESSION_KEY, true)
        ->assertSessionHas('channel', 'telegram')
        ->assertSessionHas(TelegramMode::USER_KEY, 777);

    $account = TelegramAccount::query()->where('telegram_user_id', 777)->firstOrFail();

    expect($account->first_name)->toBe('Ivan')
        ->and($account->last_name)->toBe('Petrov')
        ->and($account->username)->toBe('ivan_p')
        ->and($account->language_code)->toBe('ru')
        ->and($account->last_seen_at)->not->toBeNull()
        ->and($account->chat_started_at)->not->toBeNull()
        ->and(session(TelegramMode::ACCOUNT_KEY))->toBe($account->id);
});

it('leaves chat_started_at empty without allows_write_to_pm and updates an existing account (TM-03)', function (): void {
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 777, 'first_name' => 'Old', 'chat_started_at' => null]);

    tgSessionPost(tgInitData(user: ['id' => 777, 'first_name' => 'New']))->assertOk();

    expect($account->fresh()->first_name)->toBe('New')
        ->and($account->fresh()->chat_started_at)->toBeNull()
        ->and(TelegramAccount::query()->count())->toBe(1);
});

it('rejects a bad signature, an old date and a foreign token with 403 and creates nothing (TM-02)', function (): void {
    tgSessionPost(str_replace('Ivan', 'Eve', tgInitData()))->assertForbidden()->assertJson(['error' => 'forbidden']);
    tgSessionPost(tgInitData(authDate: time() - 7200))->assertForbidden();
    tgSessionPost(tgInitData(token: '999:OTHER'))->assertForbidden();
    tgSessionPost('')->assertForbidden();

    expect(TelegramAccount::query()->count())->toBe(0)
        ->and(session(TelegramMode::SESSION_KEY))->toBeNull();
});

it('never trusts the mode from the address (TM-03)', function (): void {
    $this->get('/?tg=1&telegram_mode=1&channel=telegram')->assertOk()
        ->assertDontSee('data-tg-topbar', false)
        ->assertDontSee('telegram-web-app.js', false);
});

it('resets the whole session when another Telegram user opens it (TM-03)', function (): void {
    $first = TelegramAccount::factory()->create(['telegram_user_id' => 111]);

    $this->withSession(tgSession($first) + ['leftover' => 'cart-of-the-first-user']);
    tgSessionPost(tgInitData(user: ['id' => 222, 'first_name' => 'Second']))->assertOk();

    $second = TelegramAccount::query()->where('telegram_user_id', 222)->firstOrFail();

    expect(session('leftover'))->toBeNull()
        ->and(session(TelegramMode::USER_KEY))->toBe(222)
        ->and(session(TelegramMode::ACCOUNT_KEY))->toBe($second->id);
});

it('keeps the session data when the same user opens the Mini App again (TM-03)', function (): void {
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 111]);

    $this->withSession(tgSession($account) + ['keep' => 'yes']);
    tgSessionPost(tgInitData(user: ['id' => 111, 'first_name' => 'Same']))->assertOk();

    expect(session('keep'))->toBe('yes');
});

it('takes the Telegram mode off when the client has no initData (TM-03)', function (): void {
    $account = TelegramAccount::factory()->create();

    $this->withSession(tgSession($account));
    $this->postJson('/tg/leave')->assertOk();

    expect(session(TelegramMode::SESSION_KEY))->toBeNull()
        ->and(session(TelegramMode::ACCOUNT_KEY))->toBeNull()
        ->and(session('channel'))->toBeNull();
});

it('stores the signed start_param and sends the page to /tg/start (TM-08)', function (): void {
    tgSessionPost(tgInitData(['start_param' => 'p_5']))->assertOk()->assertJson(['url' => '/tg/start']);
    expect(session(TelegramMode::START_KEY))->toBe('p_5');

    tgSessionPost(tgInitData(), ['to' => 'cart'])->assertOk()->assertJson(['url' => '/tg/start']);
    expect(session(TelegramMode::HINT_KEY))->toBe('cart')
        ->and(session(TelegramMode::START_KEY))->toBeNull();
});

it('signs a linked customer in at once while the auth version matches (TM-04 a)', function (): void {
    $customer = makeCustomer();
    TelegramAccount::factory()->create(['telegram_user_id' => 777, 'customer_id' => $customer->id, 'customer_auth_version' => $customer->auth_version]);

    tgSessionPost(tgInitData())->assertOk();

    expect(session(Auth::guard('customer')->getName()))->toBe($customer->id)
        ->and(session(CustomerAuthService::SESSION_VERSION_KEY))->toBe($customer->auth_version)
        ->and(session(TelegramMode::LOGIN_KEY))->toBeTrue();
});

it('does not sign in when the auto-login is switched off (TM-04 a)', function (): void {
    connectTelegramBot(['autologin_enabled' => false]);
    $customer = makeCustomer();
    TelegramAccount::factory()->create(['telegram_user_id' => 777, 'customer_id' => $customer->id, 'customer_auth_version' => $customer->auth_version]);

    tgSessionPost(tgInitData())->assertOk();

    expect(session(Auth::guard('customer')->getName()))->toBeNull();
});

it('unlinks instead of signing in when the auth version changed, the customer is blocked or anonymized (TM-04 a)', function (string $case): void {
    $customer = makeCustomer();
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 777, 'customer_id' => $customer->id, 'customer_auth_version' => $customer->auth_version]);

    match ($case) {
        'version' => $customer->bumpAuthVersion(),
        'blocked' => $customer->forceFill(['is_blocked' => true])->save(),
        'deleted' => $customer->forceFill(['anonymized_at' => now()])->save(),
    };

    tgSessionPost(tgInitData())->assertOk();

    expect(session(Auth::guard('customer')->getName()))->toBeNull()
        ->and(session(TelegramMode::LOGIN_KEY))->toBeNull()
        ->and($account->fresh()->customer_id)->toBeNull()
        ->and(Customer::query()->find($customer->id))->not->toBeNull();
})->with(['version', 'blocked', 'deleted']);

it('signs a guest in nowhere: a Telegram without a link stays a guest (TM-04 b)', function (): void {
    tgSessionPost(tgInitData())->assertOk();

    expect(session(Auth::guard('customer')->getName()))->toBeNull();
});

it('runs at most 5 SQL statements for POST /tg/session (TM-13)', function (): void {
    foreach ([false, true] as $linked) {
        $customer = makeCustomer();
        TelegramAccount::query()->where('telegram_user_id', 777)->delete();

        if ($linked) {
            TelegramAccount::factory()->create(['telegram_user_id' => 777, 'customer_id' => $customer->id, 'customer_auth_version' => $customer->auth_version]);
        }

        // A real request starts with unloaded settings: they cost queries too.
        foreach ([TelegramSettings::class, GeneralSettings::class] as $settings) {
            app()->forgetInstance($settings);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        tgSessionPost(tgInitData())->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        expect(count($queries))->toBeLessThanOrEqual(5, implode("\n", $queries));
        nextRequest();
    }
});

it('lets the entry page pass ?to= and ?start_param= of the web_app buttons as the same unsigned hint (TM-08)', function (): void {
    $html = $this->get('/tg?start_param=orders')->getContent();

    expect($html)->toContain("query.get('to') || query.get('start_param')");
});
