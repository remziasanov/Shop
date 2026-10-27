<?php

declare(strict_types=1);

use App\Support\TrustedHosts;
use Illuminate\Http\Request;

beforeEach(function () {
    config(['app.url' => 'https://shop.example', 'shop.canonical_host_redirect' => true, 'shop.extra_hosts' => []]);
    app()->detectEnvironment(fn () => 'production');
});

afterEach(function () {
    // TrustHosts sets a static list on the request class; do not leak it into other tests.
    Request::setTrustedHosts([]);
});

it('redirects the www. twin to the canonical host with 301 for GET (D-057)', function () {
    $this->get('https://www.shop.example/catalog?x=1')
        ->assertStatus(301)
        ->assertRedirect('https://shop.example/catalog?x=1');
});

it('redirects the bare host to www when APP_URL uses www', function () {
    config(['app.url' => 'https://www.shop.example']);

    $this->get('https://shop.example/catalog?x=1')->assertStatus(301)->assertRedirect('https://www.shop.example/catalog?x=1');
});

it('uses 308 for non-GET requests so the method is kept', function () {
    $this->post('https://www.shop.example/cart/add', ['a' => 1])
        ->assertStatus(308)
        ->assertRedirect('https://shop.example/cart/add');
});

it('lets the canonical host through', function () {
    $this->get('https://shop.example/up')->assertOk();
});

it('does not redirect an unrelated host (TrustHosts handles it) or outside production', function () {
    $this->get('https://evil.example/up')->assertStatus(400);

    app()->detectEnvironment(fn () => 'local');
    $this->get('https://www.shop.example/up')->assertOk();
});

it('can be switched off by config', function () {
    config(['shop.canonical_host_redirect' => false]);

    $this->get('https://www.shop.example/up')->assertOk();
});

it('builds the trusted host patterns from APP_URL, its twin and SHOP_EXTRA_HOSTS (D-057)', function () {
    expect(TrustedHosts::patterns())->toBe(['^shop\.example$', '^www\.shop\.example$']);

    config(['app.url' => 'https://www.shop.example', 'shop.extra_hosts' => ['shop.beget.tech']]);
    $patterns = TrustedHosts::patterns();

    expect($patterns)->toBe(['^shop\.example$', '^www\.shop\.example$', '^shop\.beget\.tech$']);
    expect(preg_match('{'.$patterns[2].'}i', 'evil.example'))->toBe(0);

    config(['app.url' => '']);
    expect(TrustedHosts::patterns())->toBe([]);
});
