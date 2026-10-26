<?php

declare(strict_types=1);

use App\Models\Page;
use App\Settings\AppearanceSettings;
use App\Settings\GeneralSettings;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->withoutVite();
});

it('renders the Russian 404 page in shop style (SF-07)', function () {
    generalSettings(['shop_name' => 'Лавка Тест']);

    $this->get('/no/such/page')
        ->assertNotFound()
        ->assertSee('Страница не найдена')
        ->assertSee('Лавка Тест')
        ->assertSee(route('catalog.index'), false)
        ->assertSee('На главную');
});

it('renders 500 and 503 views even when the database is unavailable (SF-07)', function () {
    // Every settings read fails, as it would with the database down.
    foreach ([GeneralSettings::class, AppearanceSettings::class] as $class) {
        app()->bind($class, fn () => throw new QueryException('sqlite', 'select 1', [], new RuntimeException('database is down')));
    }

    foreach (['500' => 'Что-то пошло не так', '503' => 'Сайт временно недоступен'] as $code => $text) {
        $html = view('errors.'.$code)->render();

        expect($html)->toContain($text)->and($html)->toContain('lang="ru"');
    }
});

it('shows the cookie banner without the cookie and hides it with it (SF-08)', function () {
    legalSettings(['cookie_banner_enabled' => true, 'cookie_banner_text' => 'Мы любим печеньки']);

    $this->get('/')->assertSee('data-cookie-banner', false)->assertSee('Мы любим печеньки')->assertSee('Принять');
    $this->withUnencryptedCookie('cookie_consent', '1')->get('/')->assertDontSee('data-cookie-banner', false);
});

it('stores consent in a one year cookie from the banner script (SF-08)', function () {
    legalSettings(['cookie_banner_enabled' => true]);

    $this->get('/')
        ->assertSee('cookie_consent=1; Max-Age=31536000; Path=/; SameSite=Lax', false)
        ->assertSee("location.protocol === 'https:'", false);
});

it('does not render the banner when disabled', function () {
    legalSettings(['cookie_banner_enabled' => false]);

    $this->get('/')->assertDontSee('data-cookie-banner', false);
});

it('links the banner to the policy page when it is active (SF-08)', function () {
    $page = Page::factory()->create(['slug' => 'privacy-x']);
    legalSettings(['cookie_banner_enabled' => true, 'privacy_page_id' => $page->id]);

    $this->get('/')->assertSee('href="'.route('page.show', 'privacy-x', false).'"', false);
});

it('reads the banner policy link from the cached menus without querying pages (SF-08)', function () {
    $page = Page::factory()->create(['slug' => 'privacy-y']);
    legalSettings(['cookie_banner_enabled' => true, 'privacy_page_id' => $page->id]);

    $this->get('/')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/')->assertOk()->assertSee('href="'.route('page.show', 'privacy-y', false).'"', false);

    expect(collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"pages"') || str_contains($q['query'], '`pages`'))->count())->toBe(0);
});
