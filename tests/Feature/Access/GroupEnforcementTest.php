<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Enums\PriceDisplayMode;
use App\Enums\StorefrontSection;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\MenuItem;
use App\Models\Page;
use App\Services\Seo\SitemapGenerator;
use Illuminate\Support\Facades\File;

/**
 * CG-04, CG-05, CG-13, CG-15 (TZ 25.9): the access matrix for the «Гость» group (the stage-8 viewer) over the three
 * category access modes, and the same pages for another group through the staff preview.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->open = Category::factory()->create(['name' => 'Открытая']);
    $this->secret = Category::factory()->create(['name' => 'Секретная']);
    $this->visible = groupProduct($this->open, 'Видимый чайник');
    $this->hidden = groupProduct($this->secret, 'Скрытый чайник');
    MenuItem::factory()->forCategory($this->open)->create(['label' => 'Меню открытая']);
    MenuItem::factory()->forCategory($this->secret)->create(['label' => 'Меню секретная']);
});

/**
 * @return array<string, array{0: CategoryAccessMode}>
 */
dataset('hiding modes', [
    'all except the secret one' => [CategoryAccessMode::AllExcept],
    'only the open one' => [CategoryAccessMode::Only],
]);

it('hides a category from the guest everywhere (CG-13)', function (CategoryAccessMode $mode): void {
    configureGuest([], $mode, $mode === CategoryAccessMode::Only ? [$this->open] : [$this->secret]);

    // Direct URLs are plain 404s, not 403.
    $this->get('/catalog/'.$this->secret->slug)->assertNotFound();
    $this->get('/product/'.$this->hidden->slug)->assertNotFound();

    // Listing, search, suggestions, menu and the category tiles never mention it.
    $this->get('/catalog')->assertOk()->assertSee('Видимый чайник')->assertDontSee('Скрытый чайник')->assertDontSee('Секретная');
    $this->get('/search?q='.rawurlencode('чайник'))->assertOk()->assertSee('Видимый чайник')->assertDontSee('Скрытый чайник');
    $this->getJson('/search/suggest?q='.rawurlencode('чайник'))->assertOk()
        ->assertJsonFragment(['name' => 'Видимый чайник'])
        ->assertJsonMissing(['name' => 'Скрытый чайник']);
    $this->get('/')->assertOk()->assertSee('Меню открытая')->assertDontSee('Меню секретная')->assertDontSee('Скрытый чайник');

    // The visible part keeps working.
    $this->get('/catalog/'.$this->open->slug)->assertOk()->assertSee('Видимый чайник');
    $this->get('/product/'.$this->visible->slug)->assertOk();
})->with('hiding modes');

it('does not show products of the hidden category on the home page blocks (CG-13)', function (): void {
    $this->hidden->update(['is_featured' => true]);
    $this->visible->update(['is_featured' => true]);
    configureGuest([], CategoryAccessMode::AllExcept, [$this->secret]);

    $this->get('/')->assertOk()->assertSee('Видимый чайник')->assertDontSee('Скрытый чайник');
});

it('does not offer similar products from the hidden category (CG-13)', function (): void {
    $sibling = groupProduct($this->open, 'Похожий открытый');
    configureGuest([], CategoryAccessMode::AllExcept, [$this->secret]);

    $this->get('/product/'.$this->visible->slug)->assertOk()->assertSee('Похожий открытый');
    // A hidden category with a sibling of its own product is never reachable at all.
    $this->get('/product/'.$sibling->slug)->assertOk()->assertDontSee('Скрытый чайник');
});

it('keeps the whole subtree of a selected category hidden (CG-05)', function (): void {
    $child = Category::factory()->childOf($this->secret)->create(['name' => 'Дочерняя секретная']);
    groupProduct($child, 'Товар дочерней');
    configureGuest([], CategoryAccessMode::AllExcept, [$this->secret]);

    $this->get('/catalog/'.$child->slug)->assertNotFound();
    $this->get('/catalog')->assertDontSee('Товар дочерней');
});

it('opens navigation parents in the «only» mode but shows just the visible subtree (CG-05)', function (): void {
    $parent = Category::factory()->create(['name' => 'Родитель']);
    $child = Category::factory()->childOf($parent)->create(['name' => 'Выбранная дочерняя']);
    $sibling = Category::factory()->childOf($parent)->create(['name' => 'Соседняя']);
    $own = groupProduct($parent, 'Свой товар родителя');
    groupProduct($child, 'Товар выбранной');
    groupProduct($sibling, 'Товар соседней');
    configureGuest([], CategoryAccessMode::Only, [$child]);

    $this->get('/catalog/'.$parent->slug)->assertOk()
        ->assertSee('Товар выбранной')
        ->assertDontSee('Свой товар родителя')
        ->assertDontSee('Товар соседней')
        ->assertSee('Выбранная дочерняя')
        ->assertDontSee('Соседняя');
    $this->get('/catalog/'.$sibling->slug)->assertNotFound();
    $this->get('/product/'.$own->slug)->assertNotFound();
    $this->get('/catalog/'.$child->slug)->assertOk()->assertSee('Товар выбранной');
});

it('builds the sitemap for the guest only (CG-15)', function (): void {
    configureGuest([], CategoryAccessMode::AllExcept, [$this->secret]);
    $dir = storage_path('framework/testing/sitemap-'.uniqid());
    File::ensureDirectoryExists($dir);

    app(SitemapGenerator::class)->generate($dir);
    $xml = file_get_contents($dir.'/sitemap.xml');
    File::deleteDirectory($dir);

    expect($xml)->toContain('/product/'.$this->visible->slug)
        ->toContain('/catalog/'.$this->open->slug)
        ->not->toContain($this->hidden->slug)
        ->not->toContain($this->secret->slug);
});

it('leaves the catalog out of the sitemap and robots.txt when the guest has no catalog (CG-15)', function (): void {
    configureGuest(['visible_sections' => []]);
    $dir = storage_path('framework/testing/sitemap-'.uniqid());
    File::ensureDirectoryExists($dir);

    app(SitemapGenerator::class)->generate($dir);
    $xml = file_get_contents($dir.'/sitemap.xml');
    File::deleteDirectory($dir);

    expect($xml)->not->toContain('/product/')->not->toContain('/catalog');
    $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /catalog', false);
});

it('redirects a guest to the sign-in stub when a section is hidden and gives a signed-in group a 404 (CG-04)', function (StorefrontSection $section, string $url): void {
    configureGuest(['visible_sections' => array_values(array_diff(StorefrontSection::defaults(), [$section->value]))]);

    $this->get($url)->assertRedirect(route('customer.login'));
    $this->get(route('customer.login'))->assertOk()->assertSee('Личный кабинет появится позже');

    $retail = CustomerGroup::factory()->withoutSection($section)->create();
    previewAs($this, $retail);
    $this->get($url)->assertNotFound();
})->with([
    'catalog' => [StorefrontSection::Catalog, '/catalog'],
    'catalog product' => [StorefrontSection::Catalog, '/product/any'],
    'search' => [StorefrontSection::Search, '/search?q=abc'],
]);

it('hides links and blocks of hidden sections (CG-04)', function (): void {
    $this->visible->update(['is_featured' => true, 'is_new' => true]);
    configureGuest(['visible_sections' => [StorefrontSection::Catalog->value]]);

    $html = $this->get('/catalog')->assertOk()->getContent();

    expect($html)->not->toContain('name="brands[]"')
        ->not->toContain('value="hit"')
        ->not->toContain('value="sale"')
        ->not->toContain('role="search"');
    $this->getJson('/search/suggest?q='.rawurlencode('чайник'))->assertOk()->assertExactJson(['items' => []]);
});

it('does not apply hidden filters from the query string (CG-04, CG-06)', function (): void {
    $this->visible->update(['is_featured' => false]);
    configureGuest(['visible_sections' => [StorefrontSection::Catalog->value]]);

    // «hit» would exclude the product if the featured section filter were applied.
    $this->get('/catalog?flags[]=hit')->assertOk()->assertSee('Видимый чайник');
});

it('serves 404 for a page hidden from the viewer group and drops it from menus (CG-10)', function (): void {
    $retail = CustomerGroup::retail();
    $page = Page::factory()->create(['title' => 'Только розница', 'visible_group_ids' => [$retail->id]]);
    $open = Page::factory()->create(['title' => 'Для всех']);
    MenuItem::factory()->forPage($page)->create(['label' => 'Ссылка розница']);
    MenuItem::factory()->forPage($open)->create(['label' => 'Ссылка всем', 'visible_group_ids' => null]);
    MenuItem::factory()->forPage($open)->create(['label' => 'Ссылка только розница', 'visible_group_ids' => [$retail->id]]);

    $this->get('/page/'.$page->slug)->assertNotFound();
    $this->get('/page/'.$open->slug)->assertOk();
    $this->get('/')->assertSee('Ссылка всем')->assertDontSee('Ссылка розница')->assertDontSee('Ссылка только розница');

    previewAs($this, $retail);
    $this->get('/page/'.$page->slug)->assertOk();
    $this->get('/')->assertSee('Ссылка только розница');
});

it('keeps a page hidden from the guest out of the sitemap (CG-10, CG-15)', function (): void {
    $retail = CustomerGroup::retail();
    $page = Page::factory()->create(['slug' => 'retail-only', 'visible_group_ids' => [$retail->id]]);
    $dir = storage_path('framework/testing/sitemap-'.uniqid());
    File::ensureDirectoryExists($dir);

    app(SitemapGenerator::class)->generate($dir);
    $xml = file_get_contents($dir.'/sitemap.xml');
    File::deleteDirectory($dir);

    expect($xml)->not->toContain('retail-only');
});

it('marks what only a non-guest may see as private and not indexable (CG-15)', function (): void {
    configureGuest([], CategoryAccessMode::AllExcept, [$this->secret]);
    $wholesale = CustomerGroup::factory()->create(['name' => 'Опт']);
    previewAs($this, $wholesale);

    $response = $this->get('/product/'.$this->hidden->slug)->assertOk();

    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private');
    $response->assertSee('<meta name="robots" content="noindex,nofollow">', false);
    $this->get('/catalog/'.$this->secret->slug)->assertOk()->assertSee('noindex', false);
});

it('answers a guest page without private headers (CG-15)', function (): void {
    $response = $this->get('/product/'.$this->visible->slug)->assertOk();

    expect($response->headers->get('X-Robots-Tag'))->toBeNull();
});

it('does not let a customer group open pages of categories it may not see through the preview of another group (CG-13)', function (): void {
    $restricted = CustomerGroup::factory()->categoryAccess(CategoryAccessMode::Only, [$this->open])->create();
    previewAs($this, $restricted);

    $this->get('/product/'.$this->hidden->slug)->assertNotFound();
    $this->get('/product/'.$this->visible->slug)->assertOk();
});

it('removes prices from HTML, structured data and suggestions when prices are hidden (CG-06)', function (PriceDisplayMode $mode): void {
    configureGuest(['price_display' => $mode]);
    $product = $this->visible;

    foreach (['/catalog', '/product/'.$product->slug, '/'] as $url) {
        $html = $this->get($url)->assertOk()->getContent();
        expect($html)->not->toContain('1 000')->not->toContain('1000.00');
    }

    $page = $this->get('/product/'.$product->slug)->assertOk()->getContent();
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $page, $blocks);
    $json = implode('', $blocks[1]);

    expect($json)->toContain('"@type":"Product"')->not->toContain('"price"')->not->toContain('priceCurrency');

    $items = $this->getJson('/search/suggest?q='.rawurlencode('чайник'))->assertOk()->json('items');
    expect($items)->not->toBeEmpty()->and($items[0]['price'])->toBeNull();

    // No price filters or sorting.
    $this->get('/catalog')->assertDontSee('name="price_from"', false)->assertDontSee('price_asc', false);
})->with([
    'request' => [PriceDisplayMode::Request],
    'login' => [PriceDisplayMode::Login],
]);

it('shows «Цена по запросу» for the request mode and a sign-in link for the login mode (CG-06)', function (): void {
    configureGuest(['price_display' => PriceDisplayMode::Request]);
    $this->get('/product/'.$this->visible->slug)->assertSee('Цена по запросу');

    configureGuest(['price_display' => PriceDisplayMode::Login]);
    $this->get('/product/'.$this->visible->slug)
        ->assertSee('Войдите, чтобы увидеть цену')
        ->assertSee('href="'.route('customer.login').'"', false);
});

it('ignores price filters and price sorting when prices are hidden (CG-06)', function (): void {
    groupProduct($this->open, 'Дорогой чайник', ['base_price' => '9000.00']);
    configureGuest(['price_display' => PriceDisplayMode::Request]);

    $this->get('/catalog?price_from=5000&sort=price_desc')->assertOk()->assertSee('Видимый чайник');
});
