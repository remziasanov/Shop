<?php

declare(strict_types=1);

use App\Enums\AgeGateMode;
use App\Enums\AgeRestriction;
use App\Models\User;
use App\Services\Home\HomePageRenderer;
use App\Settings\AgeGateSettings;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
});

/** Removes everything that legitimately differs between two requests (CSRF tokens, Livewire snapshots). */
function stableHtml(string $html): string
{
    $html = preg_replace('/(name="_token"\s+value=")[^"]+/', '$1X', $html) ?? $html;
    $html = preg_replace('/(csrf-token" content=")[^"]+/', '$1X', $html) ?? $html;
    // Livewire injects its assets only into the first response of a test process.
    $html = preg_replace('/<!-- Livewire Styles -->.*?<\/style>/s', '', $html) ?? $html;
    $html = preg_replace('/<script src="[^"]*livewire\.min\.js[^>]*><\/script>/', '', $html) ?? $html;
    $html = preg_replace('/wire:snapshot="[^"]*"/', 'wire:snapshot=""', $html) ?? $html;
    $html = preg_replace('/wire:id="[^"]*"/', 'wire:id=""', $html) ?? $html;

    return preg_replace('/\b[A-Za-z0-9]{40}\b/', 'X', $html) ?? $html;
}

// ---------------------------------------------------------------------------------------------
// Mode «site» (AG-03)
// ---------------------------------------------------------------------------------------------

it('shows the overlay on the first page in site mode when the shop has restricted products (AG-03)', function () {
    ageGate(['mode' => AgeGateMode::Site, 'site_min_age' => 18]);
    ageProduct(ageCategory(AgeRestriction::Age18));

    $this->get(route('home'))->assertOk()
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('data-age-need="18"', false)
        ->assertSee('Вам уже исполнилось 18 лет?')
        ->assertSee(route('age-gate.confirm', [], false), false);

    $this->get(route('catalog.index'))->assertSee('data-age-need="18"', false);
});

it('shows no overlay in site mode when no active product is restricted (AG-03)', function () {
    ageGate(['mode' => AgeGateMode::Site]);
    ageProduct(ageCategory(null));
    ageProduct(ageCategory(AgeRestriction::Age18, name: 'Скрытая'), ['is_active' => false]);

    $this->get(route('home'))->assertOk()->assertDontSee('data-age-need', false);
});

it('asks for the configured site age (AG-02)', function () {
    ageGate(['mode' => AgeGateMode::Site, 'site_min_age' => 16]);
    ageProduct(ageCategory(AgeRestriction::Age16));

    $this->get(route('home'))->assertSee('data-age-need="16"', false)->assertSee('Вам уже исполнилось 16 лет?');
});

it('changes nothing while the gate is disabled (AG-02)', function () {
    setSettings(AgeGateSettings::class, ['enabled' => false]);
    ageProduct(ageCategory(AgeRestriction::Age18));

    $this->get(route('home'))->assertOk()
        ->assertDontSee('data-age-need', false)
        ->assertDontSee('data-age-on', false)
        ->assertDontSee('shop_age', false);
});

// ---------------------------------------------------------------------------------------------
// Mode «restricted» (AG-04)
// ---------------------------------------------------------------------------------------------

it('shows the overlay only on restricted category and product pages in restricted mode (AG-04)', function () {
    ageGate(['mode' => AgeGateMode::Restricted]);
    $restricted = ageCategory(AgeRestriction::Age18, name: 'Табак');
    $plain = ageCategory(null, name: 'Мебель');
    $tobacco = ageProduct($restricted, ['name' => 'Трубка', 'slug' => 'trubka']);
    $chair = ageProduct($plain, ['name' => 'Стул', 'slug' => 'stul']);

    $this->get(route('home'))->assertOk()->assertDontSee('data-age-need', false);
    $this->get(route('catalog.category', $plain->slug))->assertOk()->assertDontSee('data-age-need', false);
    $this->get(route('product.show', $chair->slug))->assertOk()->assertDontSee('data-age-need', false);

    $this->get(route('catalog.category', $restricted->slug))->assertOk()->assertSee('data-age-need="18"', false);
    $this->get(route('product.show', $tobacco->slug))->assertOk()->assertSee('data-age-need="18"', false);
});

it('asks a 16+ page for 16 and an inherited subcategory page for the parent level (AG-04)', function () {
    ageGate(['mode' => AgeGateMode::Restricted]);
    $beer = ageCategory(AgeRestriction::Age16, name: 'Пиво');
    $craft = ageCategory(null, $beer, 'Крафт');

    $this->get(route('catalog.category', $beer->slug))->assertSee('data-age-need="16"', false);
    $this->get(route('catalog.category', $craft->slug))->assertSee('data-age-need="16"', false);
});

it('hides the overlay from signed-in staff and keeps the page whole', function () {
    ageGate(['mode' => AgeGateMode::Site]);
    ageProduct(ageCategory(AgeRestriction::Age18));

    $this->actingAs(User::factory()->admin()->create())->get(route('home'))->assertOk()
        ->assertDontSee('data-age-need', false)
        ->assertSee('data-age-ok="18"', false);
});

// ---------------------------------------------------------------------------------------------
// Cookie in the markup (AG-06, AG-07)
// ---------------------------------------------------------------------------------------------

it('serves the same page whatever the cookie says; CSS and the inline script decide (AG-07)', function () {
    ageGate(['mode' => AgeGateMode::Site]);
    ageProduct(ageCategory(AgeRestriction::Age18), ['name' => 'Сигары']);

    $without = stableHtml($this->get(route('home'))->assertOk()->getContent());
    $with16 = stableHtml($this->withUnencryptedCookie('shop_age', '16')->get(route('home'))->getContent());
    $with18 = stableHtml($this->withUnencryptedCookie('shop_age', '18')->get(route('home'))->getContent());

    expect($with16)->toBe($without)
        ->and($with18)->toBe($without)
        ->and($without)->toContain('shop_age=(16|18)')
        ->and($without)->toContain('data-age-need="18"');
});

it('keeps the cached home page markup independent of the cookie (AG-07)', function () {
    ageGate(['mode' => AgeGateMode::Site]);
    ageProduct(ageCategory(AgeRestriction::Age18), ['name' => 'Сигары', 'is_featured' => true]);

    $renderer = app(HomePageRenderer::class);
    Cache::forget(HomePageRenderer::cacheKey());
    $first = $renderer->render();

    expect(Cache::has(HomePageRenderer::cacheKey()))->toBeTrue();

    $this->withUnencryptedCookie('shop_age', '18')->get(route('home'))->assertOk();

    expect($renderer->render())->toBe($first)
        ->and($first)->toContain('data-age="18"');
});

it('puts the blur markup on restricted cards in every list, disabled or not (AG-05)', function () {
    $category = ageCategory(AgeRestriction::Age18, name: 'Табак');
    $plain = ageCategory(null, name: 'Мебель');
    ageProduct($category, ['name' => 'Сигары Корона', 'is_featured' => true]);
    ageProduct($category, ['name' => 'Трубка Прима', 'age_restriction' => AgeRestriction::Age16, 'is_featured' => true]);
    ageProduct($plain, ['name' => 'Кресло Обычное', 'is_featured' => true]);

    foreach ([true, false] as $enabled) {
        setSettings(AgeGateSettings::class, ['enabled' => $enabled]);

        // Catalog, category, search.
        foreach ([route('catalog.index'), route('catalog.category', $category->slug), route('search', ['q' => 'Сигары'])] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            expect($html)->toContain('data-age="18"');
        }

        $html = $this->get(route('catalog.index'))->getContent();
        expect($html)->toContain('data-age="16"')
            ->and(preg_match_all('/<article[^>]*data-age=/', $html))->toBe(2)
            ->and($html)->toContain('Сигары Корона')->toContain('Кресло Обычное');
    }

    $this->get(route('home'))->assertOk()->assertSee('data-age="18"', false);
});

it('marks restricted products in the search suggestions (AG-05)', function () {
    ageProduct(ageCategory(AgeRestriction::Age18), ['name' => 'Сигары Корона']);
    ageProduct(ageCategory(null, name: 'Сад'), ['name' => 'Сигарета для сада']);

    $items = $this->getJson(route('search.suggest', ['q' => 'сигар']))->assertOk()->json('items');
    $ages = collect($items)->pluck('age', 'name')->all();

    expect($ages)->toEqual(['Сигары Корона' => 18, 'Сигарета для сада' => null]);
});

it('marks restricted related products on the product page (AG-05)', function () {
    $category = ageCategory(AgeRestriction::Age16);
    $main = ageProduct($category, ['name' => 'Основной', 'slug' => 'osnovnoj']);
    ageProduct($category, ['name' => 'Похожий', 'age_restriction' => AgeRestriction::Age18]);

    $html = $this->get(route('product.show', $main->slug))->assertOk()->getContent();

    expect($html)->toContain('data-age="18"');
});

// ---------------------------------------------------------------------------------------------
// SEO (AG-09)
// ---------------------------------------------------------------------------------------------

it('serves robots the full page with 200 and no noindex (AG-09)', function (string $mode) {
    ageGate(['mode' => AgeGateMode::from($mode)]);
    $category = ageCategory(AgeRestriction::Age18, name: 'Табак');
    $product = ageProduct($category, ['name' => 'Сигары Корона', 'slug' => 'cigars']);
    $bot = ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];

    foreach ([route('home'), route('catalog.category', $category->slug), route('product.show', $product->slug)] as $url) {
        $response = $this->withHeaders($bot)->get($url)->assertOk();

        expect($response->getContent())->not->toContain('noindex')
            ->and($response->headers->get('X-Robots-Tag'))->toBeNull();
    }

    $this->withHeaders($bot)->get(route('product.show', $product->slug))
        ->assertSee('Сигары Корона')
        ->assertSee('application/ld+json', false);
})->with(['site', 'restricted']);

it('does not touch robots.txt or the health check (TZ 16.6)', function () {
    ageGate(['mode' => AgeGateMode::Site]);
    ageProduct(ageCategory(AgeRestriction::Age18));

    $this->get('/robots.txt')->assertOk()->assertDontSee('data-age-need', false);
    $this->get('/up')->assertOk()->assertDontSee('data-age-need', false);
});

// ---------------------------------------------------------------------------------------------
// Accessibility (AG-10)
// ---------------------------------------------------------------------------------------------

it('renders a modal dialog that cannot be dismissed without an answer (AG-10)', function () {
    ageGate(['mode' => AgeGateMode::Site]);
    ageProduct(ageCategory(AgeRestriction::Age18));

    $html = $this->get(route('home'))->getContent();

    expect($html)->toContain('role="dialog"')
        ->and($html)->toContain('aria-labelledby="age-gate-title"')
        ->and($html)->toContain('x-trap.noscroll.inert')
        ->and($html)->toContain('<form method="post" action="'.route('age-gate.confirm', [], false).'"');
});
