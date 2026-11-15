<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Models\Category;
use App\Models\Product;
use App\Services\Themes\ThemeCss;
use App\Services\Themes\ThemePreview;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/home.php';

/*
 * Tech debt group D (D-190, TD-37): the D-161 layout fixes (C3, C4 / C6, G2 / G3) and the review fixes of stages 22-23
 * are checked on the rendered pages and on the generated theme CSS. The few rules that live only in the static
 * stylesheets are read with a whitespace-tolerant lookup of the declaration, never as an exact source string.
 */

beforeEach(function (): void {
    $this->withoutVite();
    Cache::flush();
});

function renderedUseTheme(string $id): void
{
    $settings = app(AppearanceSettings::class);
    $settings->theme_id = $id;
    $settings->theme_overrides = [];
    $settings->save();
}

/** The declarations of the first rule whose selector contains `$selector` in a stylesheet of resources/css/theme. */
function renderedStaticRule(string $file, string $selector): string
{
    $css = (string) file_get_contents(resource_path('css/theme/'.$file));

    foreach (explode('}', $css) as $block) {
        if (str_contains((string) preg_replace('/\s+/', ' ', $block), $selector.' {')) {
            return (string) preg_replace('/\s+/', ' ', $block);
        }
    }

    return '';
}

function renderedThemeCss(string $id): string
{
    $registry = app(ThemeRegistry::class);

    return app(ThemeCss::class)->assets(app(ThemeResolver::class)->resolve($registry->find($id) ?? $registry->default()))['css'];
}

describe('D-161 layout fixes on the rendered pages', function (): void {
    it('renders the C3 photo tile and the rule that rounds it 8 px less than the soft tile', function (): void {
        Product::factory()->create(['stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);
        renderedUseTheme('ultrafiolet');

        $html = $this->get(route('catalog.index'))->assertOk()->getContent();

        expect($html)->toContain('data-card="C3"')->and($html)->toMatch('/<a\b[^>]*\bdata-tile\b/')
            ->and(renderedStaticRule('cards-listing.css', "[data-card='C3'] [data-tile]"))->toMatch('/border-radius: max\(0px, calc\(var\(--shop-radius\) - 8px\)\)/');
    });

    it('lets the C4 and C6 price row wrap and the card actions shrink (no horizontal scroll at 320 px)', function (string $theme, string $variant): void {
        Product::factory()->inCategory(Category::factory()->create())->create(['name' => 'Хитовый', 'base_price' => '100.00', 'stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);
        Product::factory()->inCategory(Category::factory()->create())->create(['name' => 'Без цены', 'price_on_request' => true, 'stock_qty' => 3, 'sales_mode' => SalesMode::Inquiry]);
        renderedUseTheme($theme);

        $html = $this->get(route('catalog.index'))->assertOk()->getContent();

        expect($html)->toContain('data-card="'.$variant.'"')
            ->and($html)->toContain('flex flex-wrap items-center justify-between gap-2')
            ->and($html)->not->toMatch('/data-action="(cart|inquiry)"[^>]*class="[^"]*whitespace-nowrap|class="[^"]*whitespace-nowrap[^"]*"[^>]*data-action="(cart|inquiry)"/')
            ->and($html)->toMatch('/<a\b[^>]*data-action="inquiry"[^>]*class="[^"]*\bmax-w-full\b|<a\b[^>]*class="[^"]*\bmax-w-full\b[^"]*"[^>]*data-action="inquiry"/')
            ->and($html)->not->toMatch('/<a\b[^>]*data-action="inquiry"[^>]*class="[^"]*whitespace-nowrap/');
    })->with([['volt', 'C4'], ['modul', 'C6']]);

    it('shows the G3 categories title once, as the owner set it', function (): void {
        Category::factory()->count(5)->create();
        storeHomeBlocks([
            homeBlock('slider', ['slides' => [['title' => 'Первый слайд', 'image' => bannerImage('a')], ['title' => 'Второй слайд', 'image' => bannerImage('b')]]]),
            homeBlock('categories', ['mode' => 'auto', 'max_count' => 8, 'title' => 'Разделы']),
        ]);
        renderedUseTheme('volt');

        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->toContain('data-hero-bento')->and(substr_count($html, 'data-bento-title>Разделы</h2>'))->toBe(1)
            ->and(substr_count($html, '>Разделы<'))->toBe(1)
            ->and(renderedStaticRule('header-hero.css', '.hero-bento-tile:nth-child(2) > a'))->toContain('var(--shop-')
            ->and((string) file_get_contents(resource_path('css/theme/header-hero.css')))->toContain('var(--shop-tile)')->toContain('var(--shop-accent)');
    });

    it('puts the G2 text before the picture in the markup (the phone order), with no order utility', function (): void {
        storeHomeBlocks([homeBlock('slider', ['slides' => [['title' => 'Первый слайд', 'subtitle' => 'Подзаголовок', 'image' => bannerImage('a')], ['title' => 'Второй слайд', 'image' => bannerImage('b')]]])]);
        renderedUseTheme('grafit');

        $html = $this->get(route('home'))->assertOk()->getContent();
        preg_match('/<div class="grid items-center[^"]*" data-hero-slide>(.*?)<\/section>/s', $html, $m);

        expect($m)->not->toBeEmpty()
            ->and(strpos($m[1], 'Первый слайд'))->toBeLessThan((int) strpos($m[1], '<picture'))
            ->and($m[1])->not->toMatch('/\border-(first|last|\d)/');
    });
});

describe('cart counter (TH-08, TH-34)', function (): void {
    it('sets the cart count on the accent in the on-accent color, never in the ink, on the rendered pages', function (string $theme): void {
        Product::factory()->create(['stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);
        renderedUseTheme($theme);

        $html = $this->get(route('catalog.index'))->assertOk()->getContent();
        preg_match_all('/<span\b[^>]*data-cart-count[^>]*>/', $html, $m);

        expect($m[0])->not->toBeEmpty();

        foreach ($m[0] as $tag) {
            expect($tag)->toContain('bg-accent')->toContain('text-on-accent')->not->toMatch('/\btext-ink\b/');
        }
    })->with(['opalubka', 'volt', 'default']);

    it('has no solid accent or primary fill with the ink text color on the rendered storefront pages', function (): void {
        $product = Product::factory()->inCategory(Category::factory()->create())->create(['stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);
        renderedUseTheme('opalubka');

        foreach ([route('home'), route('catalog.index'), route('product.show', $product->slug), route('cart')] as $url) {
            $html = $this->get($url)->assertOk()->getContent();
            preg_match_all('/class="([^"]*)"/', $html, $m);

            foreach ($m[1] as $classes) {
                $tokens = preg_split('/\s+/', trim($classes));

                if (array_intersect($tokens, ['bg-accent', 'bg-primary']) !== []) {
                    expect($tokens)->not->toContain('text-ink', "{$url}: {$classes}");
                }
            }
        }
    });
});

describe('pagination and card photo states (stage 22-23 review)', function (): void {
    it('marks a disabled pagination step with a non-color cue in the themes (dashed frame, not-allowed cursor)', function (): void {
        Product::factory()->count(30)->inCategory(Category::factory()->create())->create(['stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);
        renderedUseTheme('opalubka');

        $html = $this->get(route('catalog.index'))->assertOk()->getContent();

        expect($html)->toMatch('/<span\b[^>]*class="[^"]*\bcursor-not-allowed\b[^"]*"[^>]*aria-disabled="true"[^>]*data-page-disabled/')
            ->and(renderedStaticRule('surfaces.css', 'html[data-contrast] [data-page-disabled]'))->toContain('border-style: dashed');
    });

    it('keeps the transition and the press shift of the card photo link after the tile radius', function (): void {
        Product::factory()->create(['stock_qty' => 3, 'sales_mode' => SalesMode::Cart]);
        $html = $this->get(route('catalog.index'))->assertOk()->getContent();
        $css = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(resource_path('css/theme/surfaces.css')));

        expect($html)->toMatch('/<a\b[^>]*class="[^"]*\brounded-tile\b[^"]*"[^>]*data-tile\b/')
            ->and($css)->toContain("a[data-tile], [role='button']):not(:disabled, [aria-disabled='true']):active { translate: 0 1px;")
            ->and($css)->toContain("a.rounded-btn, a[data-tile], [role='button']) { transition:");
    });
});

describe('hover overrides (stage 22-23 review)', function (): void {
    it('prints the hover text colors only inside @media (hover: hover), like the hover utilities of Tailwind', function (string $theme): void {
        $css = renderedThemeCss($theme);
        $outside = (string) preg_replace('/@media \(hover:hover\)\{[^{}]*\{[^{}]*\}\}/', '', $css);

        expect($css)->toContain('@media (hover:hover){')->and($outside)->not->toContain('hover\\:text-');
    })->with(['opalubka', 'stiker', 'skviz']);
});

describe('theme preview bar (TD-38)', function (): void {
    it('has a position for every theme of the registry, so the bar needs no branch for a theme outside the list', function (): void {
        $ids = array_keys(app(ThemeRegistry::class)->all());
        $preview = app(ThemePreview::class);

        foreach ($ids as $position => $id) {
            expect($preview->neighbour($id, 1))->toBe($ids[($position + 1) % count($ids)])
                ->and($preview->neighbour($id, -1))->toBe($ids[($position - 1 + count($ids)) % count($ids)]);
        }

        $source = (string) file_get_contents(resource_path('views/storefront/partials/theme-preview-bar.blade.php'));

        expect($source)->not->toContain("['index'] !== null");
    });
});
