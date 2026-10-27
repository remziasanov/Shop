<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;

beforeEach(function () {
    $this->withoutVite();
});

it('applies primary and accent colors as CSS variables (SF-04)', function () {
    appearanceSettings(['primary_color' => '#112233', 'accent_color' => '#abcdef']);

    $this->get('/')->assertSee('--shop-primary: #112233;', false)->assertSee('--shop-accent: #abcdef;', false);
});

it('ignores invalid colors (SF-04)', function () {
    appearanceSettings(['primary_color' => 'red;}</style>']);

    $this->get('/')->assertDontSee('</style><', false)->assertSee('--shop-primary: #2563eb;', false);
});

it('maps the Tailwind theme colors to the shop variables with defaults in the CSS (SF-04)', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('--color-primary: var(--shop-primary);')
        ->and($css)->toContain('--shop-primary: #2563eb;')
        ->and($css)->not->toContain('--color-primary: var(--color-primary)');
});

it('links the favicon from the settings or falls back to /favicon.ico (SF-04)', function () {
    $this->get('/')->assertSee('<link rel="icon" href="'.asset('favicon.ico').'">', false);

    generalSettings(['favicon' => 'settings/fav.png']);

    $this->get('/')->assertSee('settings/fav.png', false);
});

it('switches the listing grid between 3 and 4 columns (SF-04)', function () {
    $category = Category::factory()->create();
    Product::factory()->inCategory($category)->create();

    appearanceSettings(['grid_columns' => 4]);
    $this->get(route('catalog.category', $category->slug))->assertSee('xl:grid-cols-4', false);

    appearanceSettings(['grid_columns' => 3]);
    $this->get(route('catalog.category', $category->slug))->assertDontSee('xl:grid-cols-4', false)->assertSee('lg:grid-cols-3', false);
});
