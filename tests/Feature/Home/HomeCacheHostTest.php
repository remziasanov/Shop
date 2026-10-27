<?php

declare(strict_types=1);

use App\Models\Product;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});

it('does not leak the host of the request that built the cached home page (D-057)', function () {
    Product::factory()->count(2)->create(['stock_qty' => 10]);

    $this->get('http://evil.example/')->assertOk();
    $html = $this->get('https://shop.example/')->assertOk()->getContent();

    expect($html)->not->toContain('evil.example')
        ->and($html)->toContain('action="/cart/add"')
        ->and($html)->toContain('href="/catalog"');
});

it('forces the root URL and https from APP_URL in production (D-057)', function () {
    config(['app.url' => 'https://shop.example']);
    $this->app['env'] = 'production';

    try {
        (new AppServiceProvider($this->app))->forceCanonicalUrl();

        expect(route('home'))->toBe('https://shop.example')
            ->and(url('/x'))->toBe('https://shop.example/x');
    } finally {
        URL::forceRootUrl(null);
        URL::forceScheme(null);
    }
});

it('leaves URL generation alone outside production', function () {
    config(['app.url' => 'https://shop.example']);

    (new AppServiceProvider($this->app))->forceCanonicalUrl();

    expect(url('/x'))->not->toBe('https://shop.example/x');
});
