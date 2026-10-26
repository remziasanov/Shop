<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Seo\SeoBuilder;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    generalSettings(['shop_name' => 'Лавка Тест', 'logo' => null]);
});

function jsonLdBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn (string $json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $m[1]);
}

it('fills the product title and description templates with placeholders (SF-81)', function () {
    seoSettings([
        'product_title_template' => '{name} за {price} — {category} | {shop}',
        'product_description_template' => 'Купить {name} в {category}. {short_description}',
    ]);
    $category = Category::factory()->create(['name' => 'Кресла']);
    $product = Product::factory()->inCategory($category)->create([
        'name' => 'Кресло Бриз', 'base_price' => '1290.00', 'short_description' => 'Мягкое и удобное',
    ]);
    $price = Money::format(Money::fromRubles($product->fresh()->final_price));

    $this->get(route('product.show', $product->slug))
        ->assertOk()
        ->assertSee('<title>Кресло Бриз за '.$price.' — Кресла | Лавка Тест</title>', false)
        ->assertSee('<meta name="description" content="Купить Кресло Бриз в Кресла. Мягкое и удобное">', false);
});

it('lets manual product meta values win over templates (SF-81)', function () {
    $product = Product::factory()->create(['meta_title' => 'Мой заголовок', 'meta_description' => 'Моё описание']);

    $this->get(route('product.show', $product->slug))
        ->assertSee('<title>Мой заголовок</title>', false)
        ->assertSee('<meta name="description" content="Моё описание">', false);
});

it('leaves {price} empty for price-on-request products', function () {
    seoSettings(['product_title_template' => '{name} {price} | {shop}']);
    $product = Product::factory()->priceOnRequest()->create(['name' => 'Стол']);

    $this->get(route('product.show', $product->slug))->assertSee('<title>Стол | Лавка Тест</title>', false);
});

it('uses category templates and manual category meta (SF-81)', function () {
    seoSettings([
        'category_title_template' => '{category} — всё в {shop}',
        'category_description_template' => 'Товары раздела {name}',
    ]);
    $category = Category::factory()->create(['name' => 'Лампы']);
    $manual = Category::factory()->create(['name' => 'Столы', 'meta_title' => 'Свой title', 'meta_description' => 'Свой description']);

    $this->get(route('catalog.category', $category->slug))
        ->assertSee('<title>Лампы — всё в Лавка Тест</title>', false)
        ->assertSee('<meta name="description" content="Товары раздела Лампы">', false);
    $this->get(route('catalog.category', $manual->slug))
        ->assertSee('<title>Свой title</title>', false)
        ->assertSee('<meta name="description" content="Свой description">', false);
});

it('uses the home title and description from the settings (SF-81)', function () {
    seoSettings(['home_title' => 'Лучший магазин', 'home_description' => 'Описание главной']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<title>Лучший магазин</title>', false)
        ->assertSee('<meta name="description" content="Описание главной">', false);

    expect(SeoBuilder::forHome()->title)->toBe('Лучший магазин');
});

it('renders canonical and Open Graph on the main pages (SF-83)', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create();
    ProductImage::factory()->create(['product_id' => $product->id, 'path_large' => 'products/1/a-1600.webp']);
    $page = Page::factory()->create(['slug' => 'delivery', 'title' => 'Доставка']);

    foreach ([
        route('home'),
        route('catalog.index'),
        route('catalog.category', $category->slug),
        route('product.show', $product->slug),
        route('page.show', $page->slug),
    ] as $url) {
        $this->get($url)->assertOk()
            ->assertSee('<link rel="canonical" href="'.$url.'">', false)
            ->assertSee('<meta property="og:url" content="'.$url.'">', false)
            ->assertSee('<meta property="og:title"', false)
            ->assertSee('<meta property="og:type"', false)
            ->assertSee('<meta name="twitter:card"', false);
    }

    $this->get(route('product.show', $product->slug))
        ->assertSee('<meta property="og:type" content="product">', false)
        ->assertSee('<meta property="og:image" content="'.url(Storage::disk('public')->url('products/1/a-1600.webp')).'">', false)
        ->assertSee('summary_large_image', false);
});

it('falls back to the default OG image and the logo (SF-83)', function () {
    seoSettings(['og_image' => 'settings/og.png']);

    $this->get(route('home'))->assertSee('settings/og.png', false);

    seoSettings(['og_image' => null]);
    generalSettings(['logo' => 'settings/logo.png']);

    $this->get(route('catalog.index'))->assertSee('settings/logo.png', false);
});

it('marks search noindex without canonical (SF-22, SF-83)', function () {
    $this->get(route('search', ['q' => 'стол']))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex,follow">', false)
        ->assertDontSee('rel="canonical"', false);
});

it('emits Product and Offer JSON-LD with availability (SF-83)', function () {
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
    $inStock = Product::factory()->create(['name' => 'Лампа', 'sku' => 'L-1', 'brand' => 'Свет', 'base_price' => '700.00', 'stock_qty' => 5]);
    $out = Product::factory()->create(['stock_qty' => 0, 'base_price' => '10.00']);

    $blocks = jsonLdBlocks($this->get(route('product.show', $inStock->slug))->getContent());
    $product = collect($blocks)->firstWhere('@type', 'Product');

    expect($product['name'])->toBe('Лампа')
        ->and($product['sku'])->toBe('L-1')
        ->and($product['brand']['name'])->toBe('Свет')
        ->and($product['offers']['@type'])->toBe('Offer')
        ->and($product['offers']['price'])->toBe('700.00')
        ->and($product['offers']['priceCurrency'])->toBe('RUB')
        ->and($product['offers']['availability'])->toBe('https://schema.org/InStock');

    $blocks = jsonLdBlocks($this->get(route('product.show', $out->slug))->getContent());

    expect(collect($blocks)->firstWhere('@type', 'Product')['offers']['availability'])->toBe('https://schema.org/OutOfStock');
});

it('reports PreOrder for backorder items', function () {
    sales(['stock_tracking' => true, 'allow_backorder' => true]);
    $product = Product::factory()->create(['stock_qty' => 0]);

    $offer = collect(jsonLdBlocks($this->get(route('product.show', $product->slug))->getContent()))->firstWhere('@type', 'Product')['offers'];

    expect($offer['availability'])->toBe('https://schema.org/PreOrder');
});

it('omits the price in JSON-LD for price-on-request products (SF-83)', function () {
    $product = Product::factory()->priceOnRequest()->create();

    $offer = collect(jsonLdBlocks($this->get(route('product.show', $product->slug))->getContent()))->firstWhere('@type', 'Product')['offers'];

    expect($offer)->not->toHaveKeys(['price', 'priceCurrency'])->and($offer)->toHaveKey('availability');
});

it('emits BreadcrumbList on category and product pages (SF-83)', function () {
    $category = Category::factory()->create(['name' => 'Лампы']);
    $product = Product::factory()->inCategory($category)->create(['name' => 'Лампа Сфера']);

    foreach ([route('catalog.category', $category->slug), route('product.show', $product->slug)] as $url) {
        $list = collect(jsonLdBlocks($this->get($url)->getContent()))->firstWhere('@type', 'BreadcrumbList');

        expect($list)->not->toBeNull()
            ->and($list['itemListElement'][0]['position'])->toBe(1)
            ->and($list['itemListElement'][0]['item'])->toBe(route('home'))
            ->and(end($list['itemListElement'])['item'])->toBe($url);
    }
});

it('emits Organization and WebSite on the home page', function () {
    $types = collect(jsonLdBlocks($this->get(route('home'))->getContent()))->pluck('@type')->all();

    expect($types)->toContain('Organization', 'WebSite');
});

it('cannot be broken out of the JSON-LD script by a hostile product name (NF-13)', function () {
    $product = Product::factory()->create(['name' => 'X</script><script>alert(1)</script>&']);

    $html = $this->get(route('product.show', $product->slug))->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    expect($m[1])->not->toBeEmpty();
    foreach ($m[1] as $json) {
        expect($json)->not->toContain('</script>')
            ->and(json_decode($json, true))->toBeArray();
    }
});

it('renders counters and verification tags from the settings (SF-84)', function () {
    seoSettings([
        'head_code' => '<script>/* head-counter */</script>',
        'body_code' => '<script>/* body-counter */</script>',
        'verification_meta' => "<meta name=\"yandex-verification\" content=\"abc123\">\n<script>evil()</script>\n<meta name=\"google-site-verification\" content=\"xyz\">",
    ]);

    $html = $this->get(route('home'))->getContent();

    expect($html)->toContain('/* head-counter */')
        ->and($html)->toContain('/* body-counter */')
        ->and($html)->toContain('<meta name="yandex-verification" content="abc123">')
        ->and($html)->toContain('<meta name="google-site-verification" content="xyz">')
        ->and($html)->not->toContain('evil()')
        ->and(strpos($html, 'head-counter'))->toBeLessThan(strpos($html, '</head>'))
        ->and(strpos($html, 'body-counter'))->toBeGreaterThan(strpos($html, '</main>'))
        ->and(strpos($html, 'body-counter'))->toBeLessThan(strpos($html, '</body>'));
});

it('renders page meta from the page values (SF-70, SF-81)', function () {
    $page = Page::factory()->create(['slug' => 'about', 'title' => 'О нас', 'meta_title' => 'О магазине', 'meta_description' => 'Всё о нас']);

    $this->get(route('page.show', $page->slug))
        ->assertSee('<title>О магазине', false)
        ->assertSee('Всё о нас', false);
});

it('emits one canonical, og:url and BreadcrumbList on a static page through SeoBuilder::page (SF-83)', function () {
    $page = Page::factory()->create(['slug' => 'about', 'title' => 'О нас', 'meta_title' => null, 'meta_description' => null, 'content' => '<p>'.str_repeat('Слово ', 80).'</p>']);
    $url = route('page.show', $page->slug);

    $html = $this->get($url)->assertOk()->getContent();
    $lists = collect(jsonLdBlocks($html))->firstWhere('@type', 'BreadcrumbList');

    expect(substr_count($html, 'rel="canonical"'))->toBe(1)
        ->and($html)->toContain('<link rel="canonical" href="'.$url.'">')
        ->and($html)->toContain('<meta property="og:url" content="'.$url.'">')
        ->and($html)->toContain('<title>О нас — Лавка Тест</title>')
        ->and($lists['itemListElement'] ?? [])->toHaveCount(2)
        ->and(mb_strlen(app(SeoBuilder::class)->page('x', null, null, '<p>'.str_repeat('Слово ', 80).'</p>', $url)->description))->toBeLessThanOrEqual(163);
});
