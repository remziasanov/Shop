<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/telegram_miniapp.php';

use App\Enums\AgeRestriction;
use App\Models\TelegramAccount;
use App\Services\Age\AgeGate;
use App\Settings\LegalSettings;
use App\Support\StorefrontCookie;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * TM-03, D-103: cookie attributes in the Telegram mode over HTTPS and over HTTP (SameSite=None needs Secure).
 */
beforeEach(function (): void {
    $this->withoutVite();
    connectTelegramBot(['miniapp_enabled' => true]);
});

/**
 * @return array<string, Cookie>
 */
function tgCookies(TestResponse $response): array
{
    $cookies = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie;
    }

    return $cookies;
}

it('gives the session and XSRF cookies of /tg SameSite=None; Secure; Partitioned over HTTPS (TM-03)', function (): void {
    $cookies = tgCookies($this->get('https://localhost/tg')->assertOk());

    foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $name) {
        expect($cookies)->toHaveKey($name)
            ->and($cookies[$name]->getSameSite())->toBe('none', $name)
            ->and($cookies[$name]->isSecure())->toBeTrue($name)
            ->and($cookies[$name]->isPartitioned())->toBeTrue($name);
    }
});

it('keeps lax without Partitioned over plain HTTP, also on /tg (TM-03)', function (): void {
    $cookies = tgCookies($this->get('/tg')->assertOk());

    foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $name) {
        expect($cookies[$name]->getSameSite())->toBe('lax', $name)
            ->and($cookies[$name]->isPartitioned())->toBeFalse($name);
    }
});

it('does the same for every page of a Telegram session over HTTPS, and not for the site (TM-03)', function (): void {
    $account = TelegramAccount::factory()->create();

    $site = tgCookies($this->get('https://localhost/')->assertOk());
    expect($site[config('session.cookie')]->getSameSite())->toBe('lax')
        ->and($site[config('session.cookie')]->isPartitioned())->toBeFalse();

    $page = tgCookies($this->withSession(tgSession($account))->get('https://localhost/catalog')->assertOk());

    expect($page[config('session.cookie')]->getSameSite())->toBe('none')
        ->and($page[config('session.cookie')]->isPartitioned())->toBeTrue()
        ->and($page['XSRF-TOKEN']->getSameSite())->toBe('none');
});

it('builds the storefront cookies through one factory with the Telegram attributes (TM-03)', function (): void {
    $account = TelegramAccount::factory()->create();

    $plain = StorefrontCookie::make('cart', 'x', 60, true, false, Request::create('http://localhost/cart'));
    expect($plain->getSameSite())->toBe('lax')->and($plain->isPartitioned())->toBeFalse();

    session(tgSession($account));

    $http = StorefrontCookie::make('cart', 'x', 60, true, false, Request::create('http://localhost/cart'));
    $https = StorefrontCookie::make('cart', 'x', 60, true, false, Request::create('https://localhost/cart'));

    expect($http->getSameSite())->toBe('lax')->and($http->isPartitioned())->toBeFalse()
        ->and($https->getSameSite())->toBe('none')->and($https->isSecure())->toBeTrue()->and($https->isPartitioned())->toBeTrue();
});

it('gives the age cookie the Telegram attributes over HTTPS in the Mini App (AG-06, TM-03)', function (): void {
    $account = TelegramAccount::factory()->create();
    session(tgSession($account));
    $this->app->instance('request', Request::create('https://localhost/catalog'));

    $cookie = app(AgeGate::class)->cookie(AgeRestriction::Age18, true);

    expect($cookie->getSameSite())->toBe('none')->and($cookie->isPartitioned())->toBeTrue()->and($cookie->isHttpOnly())->toBeFalse();
});

it('writes the consent cookie from the banner with the attributes of the mode (SF-08)', function (): void {
    $account = TelegramAccount::factory()->create();
    $settings = app(LegalSettings::class);
    $settings->cookie_banner_enabled = true;
    $settings->cookie_banner_text = 'Мы используем cookie';
    $settings->save();

    $this->get('/')->assertSee('SameSite=Lax', false)->assertDontSee('Partitioned', false);

    $this->withSession(tgSession($account))->get('https://localhost/')
        ->assertSee('SameSite=None; Secure; Partitioned', false);
});
