<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Catalog\RedirectService;

beforeEach(function (): void {
    $this->redirects = app(RedirectService::class);
});

it('records and finds a redirect', function (): void {
    $this->redirects->record('/product/old', '/product/new');

    expect($this->redirects->find('/product/old'))->toBe('/product/new')
        ->and($this->redirects->find('product/old/'))->toBe('/product/new')
        ->and($this->redirects->find('/product/none'))->toBeNull();
});

it('collapses chains', function (): void {
    $this->redirects->record('/product/a', '/product/b');
    $this->redirects->record('/product/b', '/product/c');

    expect($this->redirects->find('/product/a'))->toBe('/product/c')
        ->and($this->redirects->find('/product/b'))->toBe('/product/c')
        ->and(Redirect::count())->toBe(2);
});

it('removes loops when a slug is restored', function (): void {
    $this->redirects->record('/product/a', '/product/b');
    $this->redirects->record('/product/b', '/product/a');

    expect($this->redirects->find('/product/a'))->toBeNull()
        ->and($this->redirects->find('/product/b'))->toBe('/product/a');
});

it('ignores identical paths', function (): void {
    $this->redirects->record('/product/a', '/product/a');

    expect(Redirect::count())->toBe(0);
});

it('writes redirects when a product slug changes (SF-80)', function (): void {
    $product = Product::factory()->create(['name' => 'Стул']);
    $product->update(['slug' => 'novyi-stul']);
    $product->update(['slug' => 'samyi-novyi']);

    expect($this->redirects->find('/product/stul'))->toBe('/product/samyi-novyi')
        ->and($this->redirects->find('/product/novyi-stul'))->toBe('/product/samyi-novyi');
});

it('writes redirects when a category slug changes', function (): void {
    $category = Category::factory()->create(['name' => 'Мебель']);
    $category->update(['slug' => 'furniture']);

    expect($this->redirects->find('/catalog/mebel'))->toBe('/catalog/furniture');
});

it('does not write a redirect when the slug is unchanged or on creation', function (): void {
    $product = Product::factory()->create();
    $product->update(['name' => 'Другое имя']);

    expect(Redirect::count())->toBe(0)->and($product->fresh()->slug)->toBe($product->slug);
});
