<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/telegram_miniapp.php';

use App\Enums\AgeGateMode;
use App\Enums\AgeRestriction;
use App\Enums\SalesMode;
use App\Models\Category;
use App\Models\Product;
use App\Models\TelegramAccount;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;

/**
 * TM-06, TM-07, TM-09, TM-11, TM-12, TM-13: the Telegram layout, the frame headers, the script, the SQL budget.
 */
beforeEach(function (): void {
    $this->withoutVite();
    connectTelegramBot(['miniapp_enabled' => true]);
});

function tgVisitor(): TelegramAccount
{
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 777]);
    test()->withSession(tgSession($account));

    return $account;
}

it('renders the Telegram layout only in a Telegram session (TM-06, TM-13)', function (): void {
    $this->get('/')->assertOk()
        ->assertDontSee('data-tg-topbar', false)
        ->assertDontSee('telegram-web-app.js', false)
        ->assertDontSee('x-data="tgBridge', false);

    tgVisitor();

    $this->get('/')->assertOk()
        ->assertSee('data-tg-topbar', false)
        ->assertSee('https://telegram.org/js/telegram-web-app.js', false)
        ->assertSee('tgBridge', false)
        ->assertSee('data-bottom-nav', false)
        ->assertDontSee('data-header=', false)
        ->assertDontSee('<footer', false);
});

it('ignores a forged query parameter and a forged channel field (TM-03, D-101)', function (): void {
    $this->get('/catalog?tg=1&mode=telegram&telegram_mode=1')->assertOk()
        ->assertDontSee('data-tg-topbar', false);
});

it('shares the theme tokens with the Telegram layout (TH-15)', function (): void {
    tgVisitor();

    $html = $this->get('/')->getContent();

    expect($html)->toContain('--shop-primary')
        ->and($html)->toContain('data-tg-colors="shop"');
});

it('puts the colour mode of the settings into the layout (TM-07)', function (): void {
    connectTelegramBot(['miniapp_colors' => 'telegram']);
    tgVisitor();

    $this->get('/')->assertSee('data-tg-colors="telegram"', false);
});

it('marks the main buttons with data-tg-main (TM-07)', function (): void {
    tgVisitor();
    $product = Product::factory()->inCategory(Category::factory()->create())->create(['base_price' => '100.00', 'stock_qty' => 5, 'sales_mode' => SalesMode::Both]);

    $this->get('/product/'.$product->slug)->assertOk()->assertSee('data-tg-main', false);
});

it('sends the frame headers of Telegram on /tg and in the Telegram mode only (TM-12)', function (): void {
    $this->get('/')->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeaderMissing('Content-Security-Policy');

    $entry = $this->get('/tg');
    $entry->assertHeaderMissing('X-Frame-Options');
    expect($entry->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self' https://web.telegram.org");

    tgVisitor();

    $page = $this->get('/catalog');
    $page->assertHeaderMissing('X-Frame-Options');
    expect($page->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'self' https://web.telegram.org");
});

it('never lets the admin panel be framed, even in the Telegram mode (TM-12)', function (): void {
    tgVisitor();

    $admin = trim((string) config('shop.admin_path'), '/');
    $response = $this->get('/'.$admin.'/login');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('shows the closed-shop page in the Telegram mode for a visitor (TM-11)', function (): void {
    $general = app(GeneralSettings::class);
    $general->shop_open = false;
    $general->closed_message = 'Закрыто на учёт';
    $general->save();

    tgVisitor();

    $this->get('/')->assertStatus(503)->assertSee('Закрыто на учёт');
});

it('stays within 30 SQL statements on the pages of the Mini App (TM-13, NF-02)', function (): void {
    tgVisitor();
    $category = Category::factory()->create();
    Product::factory()->count(6)->inCategory($category)->create(['base_price' => '100.00']);
    $product = Product::query()->first();

    foreach (['/', '/catalog', '/catalog/'.$category->slug, '/product/'.$product->slug, '/cart', '/tg/me', '/tg/about'] as $path) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($path)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($count)->toBeLessThanOrEqual(30, $path.' ran '.$count.' queries');
    }
});

it('offers «Открыть в Telegram» on the site only with verified startapp links and shares inside the Mini App (TM-09)', function (): void {
    $product = Product::factory()->inCategory(Category::factory()->create())->create();

    $this->get('/product/'.$product->slug)->assertDontSee('data-open-in-telegram', false);

    connectTelegramBot(['startapp_verified_at' => now()->toIso8601String()]);

    $this->get('/product/'.$product->slug)
        ->assertSee('data-open-in-telegram', false)
        ->assertSee('https://t.me/test_shop_bot?startapp=p_'.$product->id, false);

    tgVisitor();

    $this->get('/product/'.$product->slug)
        ->assertDontSee('data-open-in-telegram', false)
        ->assertSee('data-tg-share', false);
});

it('shows the shop details under «О магазине» and the cabinet for a guest (TM-04, TM-06)', function (): void {
    tgVisitor();

    $this->get('/tg/about')->assertOk()->assertSee('О магазине');
    $this->get('/tg/me')->assertOk()->assertSee('Войти в аккаунт сайта')->assertSee('Открыть сайт в браузере');
});

it('has no /tg/me and /tg/about outside the Telegram mode (TM-06)', function (): void {
    $this->get('/tg/me')->assertRedirect(route('home'));
    $this->get('/tg/about')->assertRedirect(route('home'));
});

it('shows the age gate in the Telegram layout and accepts the answer with the cookie (TM-11, AG-06)', function (): void {
    ageGate(['mode' => AgeGateMode::Site, 'site_min_age' => 18]);
    ageProduct(ageCategory(AgeRestriction::Age18));
    tgVisitor();

    $this->get('/')->assertOk()->assertSee('role="dialog"', false)->assertSee('aria-modal="true"', false)->assertSee('data-tg-topbar', false);

    $response = $this->post(route('age-gate.confirm'), ['level' => '18', 'return' => '/']);
    $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === 'shop_age');

    expect($cookie)->not->toBeNull()->and($cookie->getValue())->toBe('18');
});
