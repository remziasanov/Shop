<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Enums\CategoryAccessMode;
use App\Models\Category;
use App\Services\Catalog\AttributeSyncService;
use App\Services\Catalog\CatalogVersion;
use App\Services\Seo\SitemapGenerator;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;

/**
 * FL-14, 20.6: indexing rules of filtered category pages, sitemap, robots.txt, links on the product card.
 */
beforeEach(function (): void {
    $this->withoutVite();
    attrMigrated();
    config(['app.url' => 'https://shop.example']);

    $this->category = Category::factory()->create(['slug' => 'odezhda', 'name' => 'Одежда']);
    $this->material = facetAttr('material', AttributeType::List, ['name' => 'Материал', 'index_single_value' => true]);
    $this->cotton = facetValue($this->material, 'cotton', 'Хлопок', 1);
    $this->linen = facetValue($this->material, 'linen', 'Лён', 2);
    $this->color = facetAttr('color', AttributeType::List, ['name' => 'Цвет']);
    $this->red = facetValue($this->color, 'red', 'Красный');
    $this->power = facetAttr('power', AttributeType::Number, ['name' => 'Мощность', 'index_single_value' => true]);
    facetSet($this->category, [$this->material, $this->color, $this->power]);
    foreach (range(1, 4) as $i) {
        facetProduct($this->category, [[$this->material, $this->cotton], [$this->color, $this->red], [$this->power, (string) (100 * $i)]]);
    }
    facetProduct($this->category, [[$this->material, $this->linen]]);

    $this->url = route('catalog.category', $this->category->slug);
});

function seoPage(string $query): TestResponse
{
    // A raw spelling of the selection is redirected to the normalized URL (FL-07): the page is the one it leads to.
    return test()->followingRedirects()->get(route('catalog.category', 'odezhda').$query)->assertOk();
}

it('(а) keeps a page without characteristic filters as before', function (): void {
    $html = seoPage('')->getContent();

    expect($html)->toContain('<link rel="canonical" href="'.$this->url.'">')
        ->and($html)->not->toContain('name="robots"')
        ->and($html)->toContain('<h1 class="mb-4 text-2xl font-bold md:text-3xl">Одежда</h1>');

    // Built-in filters keep SF-14: indexable, canonical without sort.
    $html = seoPage('?in_stock=1&sort=name')->getContent();
    expect($html)->toContain('<link rel="canonical" href="'.$this->url.'?in_stock=1">')
        ->and($html)->not->toContain('name="robots"');
});

it('(б) indexes a single value of an indexed characteristic with its own canonical, title and h1', function (): void {
    $response = seoPage('?a[material][]=cotton');
    $html = $response->getContent();
    $canonical = $this->url.'?'.http_build_query(['a' => ['material' => ['cotton']]]);

    expect($html)->toContain('<link rel="canonical" href="'.e($canonical).'">')
        ->and($html)->not->toContain('name="robots"')
        ->and($html)->toContain('Одежда — Хлопок</h1>')
        ->and($html)->toContain('<title>Одежда — Хлопок')
        ->and($response->viewData('heading'))->toBe('Одежда — Хлопок');

    // Another spelling of the same selection has the same canonical.
    expect(seoPage('?a[material][0]=cotton&utm=1')->getContent())->toContain('<link rel="canonical" href="'.e($canonical).'">');
});

it('(б) fills the {filters} placeholder of the category templates (20.6)', function (): void {
    seoSettings(['category_title_template' => '{name}: {filters} | {shop}', 'category_description_template' => 'Купить {category} ({filters})']);

    $html = seoPage('?a[material][]=cotton')->getContent();

    expect($html)->toContain('<title>Одежда: Хлопок |')
        ->and($html)->toContain('content="Купить Одежда (Хлопок)"');

    // Without a filter the placeholder is empty and the separators are trimmed.
    expect(seoPage('')->getContent())->toContain('<title>Одежда: |');
});

it('(в) sends combinations, ranges, flags, sort, built-in filters and pages to noindex with the category canonical', function (string $query): void {
    $html = seoPage($query)->getContent();

    expect($html)->toContain('<meta name="robots" content="noindex,follow">')
        ->and($html)->toContain('<link rel="canonical" href="'.$this->url.'">')
        ->and($html)->toContain('Одежда</h1>');
})->with([
    'two values of one' => '?a[material][]=cotton&a[material][]=linen',
    'combination' => '?a[material][]=cotton&a[color][]=red',
    'number range' => '?a[power][min]=100&a[power][max]=300',
    'not indexed characteristic' => '?a[color][]=red',
    'sort' => '?a[material][]=cotton&sort=name',
    'built-in filter' => '?a[material][]=cotton&in_stock=1',
    'brand' => '?a[material][]=cotton&brands[]=Acme',
]);

it('(в) noindexes the second page of a filtered listing', function (): void {
    foreach (range(1, 30) as $i) {
        facetProduct($this->category, [[$this->material, $this->cotton]]);
    }

    expect(seoPage('?a[material][]=cotton')->getContent())->not->toContain('name="robots"');

    $html = seoPage('?a[material][]=cotton&page=2')->getContent();
    expect($html)->toContain('<meta name="robots" content="noindex,follow">')
        ->and($html)->toContain('<link rel="canonical" href="'.$this->url.'">');
});

it('(в) does not index a characteristic whose flag is off even for one value', function (): void {
    $this->material->update(['index_single_value' => false]);

    expect(seoPage('?a[material][]=cotton')->getContent())->toContain('<meta name="robots" content="noindex,follow">');
});

it('uses the inherited set for the (б) rule of a subcategory', function (): void {
    $child = Category::factory()->childOf($this->category)->create(['slug' => 'futbolki', 'name' => 'Футболки']);
    foreach (range(1, 3) as $i) {
        facetProduct($child, [[$this->material, $this->cotton]]);
    }

    $html = $this->get(route('catalog.category', 'futbolki').'?a[material][]=cotton')->assertOk()->getContent();

    expect($html)->toContain('Футболки — Хлопок</h1>')
        ->and($html)->not->toContain('name="robots"');
});

it('(б) sends a single-value page with fewer than seo_single_value_min_products products to noindex,follow, like the sitemap', function (): void {
    // The category has 4 products with cotton and 1 with linen; the default threshold is 3.
    $settings = app(CatalogSettings::class);
    expect($settings->seo_single_value_min_products)->toBe(3);

    $thin = seoPage('?a[material][]=linen')->getContent();
    expect($thin)->toContain('<meta name="robots" content="noindex,follow">')
        ->and($thin)->toContain('<link rel="canonical" href="'.$this->url.'">')
        ->and($thin)->not->toContain('Одежда — Лён</h1>');

    expect(seoPage('?a[material][]=cotton')->getContent())->not->toContain('name="robots"');

    // Lowering the threshold makes the same page indexable again.
    $settings->seo_single_value_min_products = 1;
    $settings->save();

    expect(seoPage('?a[material][]=linen')->getContent())->not->toContain('name="robots"')
        ->and(seoPage('?a[material][]=linen')->getContent())->toContain('Одежда — Лён</h1>');
});

it('redirects a raw or differently written filter query to the normalized URL with 301 (FL-07)', function (): void {
    $normalized = $this->url.'?'.http_build_query(['sort' => 'name', 'a' => ['material' => ['cotton', 'linen'], 'power' => ['min' => '100', 'max' => '300']]]);

    // Another order, a decimal comma, empty bounds of an unused filter, indexed list keys.
    $raw = $this->url.'?a[power][max]=300&a[power][min]=100&a[material][5]=cotton&a[material][]=linen&a[color][]=&sort=name';

    $this->get($raw)->assertStatus(301)->assertRedirect($normalized);

    // The normalized URL is final: no loop, no redirect.
    $this->get($normalized)->assertOk();

    // Other parameters are kept; page and built-in filters stay as normalized.
    $this->get($this->url.'?utm_source=x&page=1&in_stock=1&a[material][]=cotton')
        ->assertStatus(301)
        ->assertRedirect($this->url.'?in_stock=1&'.http_build_query(['a' => ['material' => ['cotton']]]).'&utm_source=x');

    // Only GET is redirected, and pages without a characteristic filter are left alone.
    $this->post($raw)->assertStatus(405);
    $this->get($this->url.'?sort=name&in_stock=1')->assertOk();
    $this->get($this->url.'?a[unknown][]=x')->assertOk();
});

describe('sitemap', function (): void {
    beforeEach(function (): void {
        $this->dir = storage_path('framework/testing/sitemap-'.uniqid());
        File::ensureDirectoryExists($this->dir);
        config(['shop.sitemap_dir' => $this->dir]);
    });

    afterEach(fn () => File::deleteDirectory($this->dir));

    it('lists the single-value pages with at least seo_single_value_min_products products (FL-14)', function (): void {
        app(SitemapGenerator::class)->generate();
        $xml = file_get_contents($this->dir.'/sitemap.xml');
        $cotton = 'https://shop.example/catalog/odezhda?'.http_build_query(['a' => ['material' => ['cotton']]]);

        // cotton: 4 products (>= 3); linen: 1 product; color is not indexed; power is a number.
        expect($xml)->toContain('<loc>'.htmlspecialchars($cotton).'</loc>')
            ->and($xml)->not->toContain('linen')
            ->and($xml)->not->toContain('color')
            ->and($xml)->not->toContain('power')
            ->and(simplexml_load_string($xml))->not->toBeFalse();
    });

    it('follows the setting, counts the subtree and ignores inactive products and hidden categories', function (): void {
        $settings = app(CatalogSettings::class);
        $settings->seo_single_value_min_products = 1;
        $settings->save();
        $child = Category::factory()->childOf($this->category)->create(['slug' => 'futbolki']);
        facetProduct($child, [[$this->material, $this->linen]], ['is_active' => false]);

        app(SitemapGenerator::class)->generate();
        $xml = file_get_contents($this->dir.'/sitemap.xml');

        // linen has one active product in the parent: listed with min = 1; the child has the inherited set but no active product.
        expect($xml)->toContain('material%5D%5B0%5D=linen')
            ->and(substr_count($xml, 'odezhda?'))->toBe(2)
            ->and($xml)->not->toContain('futbolki?');

        $settings->seo_single_value_min_products = 5;
        $settings->save();
        app(SitemapGenerator::class)->generate();
        expect(file_get_contents($this->dir.'/sitemap.xml'))->not->toContain('odezhda?');

        // The guest does not see the category at all: no filter pages either.
        $settings->seo_single_value_min_products = 1;
        $settings->save();
        configureGuest([], CategoryAccessMode::AllExcept, [$this->category]);
        app(SitemapGenerator::class)->generate();
        expect(file_get_contents($this->dir.'/sitemap.xml'))->not->toContain('odezhda');
    });

    it('adds nothing before the migration from JSON has finished', function (): void {
        $settings = app(CatalogSettings::class);
        $settings->attributes_migrated_at = null;
        $settings->save();

        app(SitemapGenerator::class)->generate();

        expect(file_get_contents($this->dir.'/sitemap.xml'))->not->toContain('odezhda?');
    });
});

it('does not block query parameters in robots.txt (20.6)', function (): void {
    $text = $this->get('/robots.txt')->assertOk()->getContent();

    expect($text)->not->toMatch('/^Disallow:.*[?*=]/m')
        ->and($text)->not->toContain('Disallow: /catalog');
});

describe('product card links', function (): void {
    it('links values of indexed characteristics of the set to the filtered category (20.6)', function (): void {
        $product = facetProduct($this->category, [[$this->material, $this->cotton], [$this->color, $this->red]], ['slug' => 'rubashka']);
        app(AttributeSyncService::class)->syncProduct($product);

        $html = $this->get(route('product.show', 'rubashka'))->assertOk()->getContent();
        $link = e($this->url.'?'.http_build_query(['a' => ['material' => ['cotton']]]));

        expect($html)->toContain('<a href="'.$link.'" class="text-primary underline">Хлопок</a>')
            // Not indexed: plain text; the JSON cache is still the source of the table.
            ->and($html)->toContain('Красный')
            ->and($html)->not->toContain('a%5Bcolor%5D');
    });

    it('does not link when the category set lacks the characteristic or before the migration', function (): void {
        $other = Category::factory()->create(['slug' => 'obuv']);
        facetSet($other, [$this->color]);
        $shoe = facetProduct($other, [[$this->material, $this->cotton]], ['slug' => 'bot']);
        app(AttributeSyncService::class)->syncProduct($shoe);

        expect($this->get(route('product.show', 'bot'))->assertOk()->getContent())->not->toContain('a%5Bmaterial%5D');

        $product = facetProduct($this->category, [[$this->material, $this->cotton]], ['slug' => 'rubashka']);
        app(AttributeSyncService::class)->syncProduct($product);
        $settings = app(CatalogSettings::class);
        $settings->attributes_migrated_at = null;
        $settings->save();
        CatalogVersion::bump();

        expect($this->get(route('product.show', 'rubashka'))->assertOk()->getContent())->not->toContain('a%5Bmaterial%5D');
    });
});
