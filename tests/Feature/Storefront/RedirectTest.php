<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Catalog\RedirectService;

beforeEach(function () {
    $this->withoutVite();
});

it('redirects an old product slug to the new one with 301 (SF-80)', function () {
    $product = Product::factory()->create(['name' => 'Старое имя']);
    $oldSlug = $product->slug;
    $product->update(['slug' => 'novoe-imya']);

    $this->get(route('product.show', $oldSlug))
        ->assertStatus(301)
        ->assertRedirect(route('product.show', 'novoe-imya'));

    $this->get(route('product.show', 'novoe-imya'))->assertOk();
});

it('redirects an old category slug and keeps the query string (SF-80)', function () {
    $category = Category::factory()->create(['name' => 'Старая']);
    $oldSlug = $category->slug;
    $category->update(['slug' => 'novaya']);

    $this->get(route('catalog.category', $oldSlug).'?sort=price_asc&page=2')
        ->assertStatus(301)
        ->assertRedirect(route('catalog.category', 'novaya').'?sort=price_asc&page=2');
});

it('redirects HEAD requests but never POST requests', function () {
    app(RedirectService::class)->record('/product/old', '/product/new');

    $this->call('HEAD', '/product/old')->assertStatus(301);
    $this->post('/product/old')->assertStatus(405);
});

it('keeps answering 404 when there is no redirect', function () {
    $this->get('/product/nothing-here')->assertNotFound();
    $this->get('/some/other/page')->assertNotFound();
});

it('does not redirect from the admin path', function () {
    $admin = '/'.trim((string) config('shop.admin_path'), '/');
    app(RedirectService::class)->record($admin.'/nothing', '/product/new');

    $this->get($admin.'/nothing')->assertNotFound();
});

it('does not redirect to external or protocol-relative targets', function () {
    Redirect::query()->create(['from_path' => '/product/evil', 'to_path' => '//evil.example/x']);

    $this->get('/product/evil')->assertNotFound();
});

it('answers with a relative Location regardless of the Host header', function () {
    app(RedirectService::class)->record('/product/old', '/product/new');

    $response = $this->call('GET', 'http://evil.example/product/old', ['a' => '1']);

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toStartWith('/')->toBe('/product/new?a=1');
});
