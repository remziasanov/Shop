<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Home\HomePageRenderer;
use App\Services\Themes\ThemeCss;
use App\Services\Themes\ThemeManager;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->withoutVite();
});

function setTheme(string $id, array $overrides = []): void
{
    $settings = app(AppearanceSettings::class);
    $settings->theme_id = $id;
    $settings->theme_overrides = $overrides === [] ? [] : [$id => $overrides];
    $settings->save();
}

/** The text of the :root token block printed in the page. */
function rootBlock(string $html): string
{
    preg_match('/:root\{[^}]*\}/', $html, $m);

    return $m[0] ?? '';
}

describe('settings migration (TH-03)', function (): void {
    it('activates default with no overrides on a fresh install', function (): void {
        $settings = app(AppearanceSettings::class);

        expect($settings->theme_id)->toBe('default')->and($settings->theme_overrides)->toBe([]);
    });

    it('moves a customized primary and accent into the default theme overrides', function (): void {
        $migration = require database_path('settings/2026_11_21_000300_add_appearance_theme_settings.php');
        $migration->down();
        DB::table('settings')->where('group', 'appearance')->where('name', 'primary_color')->update(['payload' => json_encode('#112233')]);
        DB::table('settings')->where('group', 'appearance')->where('name', 'accent_color')->update(['payload' => json_encode('#ABCDEF')]);

        $migration->up();

        $stored = json_decode((string) DB::table('settings')->where('group', 'appearance')->where('name', 'theme_overrides')->value('payload'), true);

        expect($stored)->toEqual(['default' => ['colors' => ['primary' => '#112233', 'accent' => '#ABCDEF']]]);

        $html = $this->get(route('home'))->assertOk()->getContent();
        expect(rootBlock($html))->toContain('--shop-primary: #112233;')->and(rootBlock($html))->toContain('--shop-accent: #abcdef;');
    });

    it('stores nothing when the colors were the old defaults, and reverts with down()', function (): void {
        $migration = require database_path('settings/2026_11_21_000300_add_appearance_theme_settings.php');
        $migration->down();
        expect(DB::table('settings')->where('group', 'appearance')->where('name', 'theme_id')->exists())->toBeFalse();

        $migration->up();

        expect(app(AppearanceSettings::class)->refresh()->theme_overrides)->toBe([]);
    });
});

describe('storefront output (TH-05, TH-18, TH-19)', function (): void {
    it('prints the tokens of the active theme and its fonts only', function (): void {
        setTheme('volt');
        $html = $this->get(route('home'))->assertOk()->getContent();

        expect(rootBlock($html))->toContain('--shop-bg: #f6f8fb;')->toContain('--shop-primary: #1f4fe0;')
            ->and($html)->toContain('golos-text-cyrillic-700-normal.woff2')
            ->and($html)->toContain('rel="preload"')
            ->and($html)->not->toContain('manrope')
            ->and($html)->not->toContain('fonts.googleapis.com')
            ->and($html)->not->toContain('fonts.gstatic.com');
    });

    it('applies the overrides of the active theme (TH-04)', function (): void {
        setTheme('volt', ['colors' => ['primary' => '#FF0000'], 'fonts' => ['display' => 'Manrope']]);
        $html = $this->get(route('home'))->getContent();

        expect(rootBlock($html))->toContain('--shop-primary: #ff0000;')->toContain('--shop-font-display: "Manrope",')
            ->and($html)->toContain('manrope-cyrillic-')
            ->and($html)->not->toContain('golos-text');
    });

    it('keeps overrides per theme: switching back restores them (TH-04)', function (): void {
        $settings = app(AppearanceSettings::class);
        $settings->theme_id = 'volt';
        $settings->theme_overrides = ['volt' => ['colors' => ['primary' => '#FF0000']], 'monohrom' => ['colors' => ['primary' => '#00FF00']]];
        $settings->save();

        expect(rootBlock($this->get(route('home'))->getContent()))->toContain('--shop-primary: #ff0000;');

        $settings->theme_id = 'monohrom';
        $settings->save();
        expect(rootBlock($this->get(route('home'))->getContent()))->toContain('--shop-primary: #00ff00;');

        $settings->theme_id = 'volt';
        $settings->save();
        expect(rootBlock($this->get(route('home'))->getContent()))->toContain('--shop-primary: #ff0000;');
    });

    it('never prints junk from settings (TH-09)', function (): void {
        setTheme('volt', ['colors' => ['bg' => 'red;}</style><script>alert(1)</script>', 'ink' => 'expression(1)'], 'fonts' => ['display' => '"};</style>']]);
        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->not->toContain('alert(1)')->and(rootBlock($html))->toContain('--shop-bg: #f6f8fb;');
    });

    it('falls back to default for an unknown theme id and logs it (TH-02)', function (): void {
        Log::spy();
        setTheme('does-not-exist');

        $html = $this->get(route('home'))->assertOk()->getContent();

        expect(rootBlock($html))->toContain('--shop-primary: #2563eb;');
        Log::shouldHaveReceived('warning')->atLeast()->once();
    });

    it('caches the generated css per theme and overrides (D-137)', function (): void {
        $registry = app(ThemeRegistry::class);
        $resolver = app(ThemeResolver::class);
        $key = fn (array $overrides): string => ThemeCss::cacheKey($resolver->resolve($registry->find('volt'), $overrides), $registry->version());

        setTheme('volt');
        $this->get(route('home'))->assertOk();
        expect(Cache::has($key([])))->toBeTrue()->and(Cache::has($key(['colors' => ['primary' => '#FF0000']])))->toBeFalse();

        setTheme('volt', ['colors' => ['primary' => '#FF0000']]);
        expect(rootBlock($this->get(route('home'))->getContent()))->toContain('--shop-primary: #ff0000;')
            ->and(Cache::has($key(['colors' => ['primary' => '#FF0000']])))->toBeTrue();
    });

    it('puts the theme into the home cache key (TH-12)', function (): void {
        setTheme('volt');
        $a = HomePageRenderer::cacheKey();
        $this->get(route('home'));
        setTheme('volt', ['colors' => ['primary' => '#FF0000']]);
        $this->get(route('home'));
        $b = HomePageRenderer::cacheKey();
        setTheme('monohrom');
        $this->get(route('home'));
        $c = HomePageRenderer::cacheKey();

        expect($a)->toContain('volt')->and($b)->not->toBe($a)->and($c)->toContain('monohrom');
    });
});

describe('every theme renders the main pages (TH-32)', function (): void {
    it('renders home, catalog, category, product, cart and checkout with its tokens', function (string $id): void {
        $theme = app(ThemeRegistry::class)->find($id);
        $category = Category::factory()->create(['name' => 'Тема']);
        $product = Product::factory()->inCategory($category)->create(['stock_qty' => 5]);
        setTheme($id);

        // A product in the cart (cookie), otherwise /checkout redirects to /cart.
        sales(['min_order_amount' => 0]);
        $cart = cartWith(sellable());
        $this->withCookie('cart', $cart->token);

        $urls = [route('home'), route('catalog.index'), route('catalog.category', $category->slug), route('product.show', $product->slug), route('cart'), route('checkout')];

        foreach ($urls as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            expect(rootBlock($html))->toContain('--shop-bg: '.strtolower($theme->colors['bg']).';')
                ->toContain('--shop-primary: '.strtolower($theme->colors['primary']).';')
                ->toContain('--shop-radius: '.$theme->shape['radius'].'px;')
                ->and($html)->not->toContain('fonts.googleapis.com')
                ->and($html)->not->toContain('fonts.gstatic.com');
        }
    })->with(fn () => array_merge(['default'], array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/themes/themes.json'), true)['themes'], 'id')));
});

describe('display font (TH-19, TH-20)', function (): void {
    it('prints the display weight / line-height / tracking tokens only when the theme sets them', function (): void {
        $default = rootBlock($this->get(route('home'))->assertOk()->getContent());
        expect($default)->toContain('--shop-font-display:')
            ->not->toContain('--shop-display-weight-set')
            ->not->toContain('--shop-display-line-height')
            ->not->toContain('--shop-display-tracking');

        setTheme('arkada');
        $arkada = rootBlock($this->get(route('home'))->assertOk()->getContent());
        expect($arkada)->toContain('--shop-font-display: "Press Start 2P"')
            ->toContain('--shop-display-weight-set: 400;')
            ->toContain('--shop-display-line-height: 1.5;')
            ->not->toContain('--shop-display-tracking');

        setTheme('modul');
        $modul = rootBlock($this->get(route('home'))->assertOk()->getContent());
        expect($modul)->not->toContain('--shop-display-weight-set')
            ->not->toContain('--shop-display-line-height')
            ->toContain('--shop-display-tracking: -0.03em;');
    });

    it('applies the display tokens to headings in the css source, except headings that opt out with font-body', function (): void {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        expect($css)->toContain(':is(h1, h2, h3, .font-display):not(.font-body)')
            ->toContain('font-family: var(--shop-font-display);')
            ->toContain('var(--shop-display-weight-set, revert-layer)')
            ->toContain('var(--shop-display-scale)')
            ->toContain('--font-body: var(--shop-font-body);');
    });

    it('marks the product card name heading with font-body', function (): void {
        $source = (string) file_get_contents(resource_path('views/storefront/partials/product-card.blade.php'));

        expect($source)->toMatch('/<h3 class="[^"]*\bfont-body\b/');
    });

    it('forgets the css cache key of the previous state when overrides are saved or the theme changes', function (): void {
        $admin = User::factory()->admin()->create();
        $manager = app(ThemeManager::class);
        $registry = app(ThemeRegistry::class);
        $resolver = app(ThemeResolver::class);
        $keyOf = fn (string $id, array $overrides): string => ThemeCss::cacheKey($resolver->resolve($registry->findOrDefault($id), $overrides), $registry->version());

        $oldKey = $keyOf('default', []);
        $this->get(route('home'))->assertOk();
        expect(Cache::has($oldKey))->toBeTrue();

        $manager->saveOverrides($admin, 'default', ['colors' => ['primary' => '#112233']]);
        expect(Cache::has($oldKey))->toBeFalse();

        $this->get(route('home'))->assertOk();
        $overrideKey = $keyOf('default', ['colors' => ['primary' => '#112233']]);
        expect(Cache::has($overrideKey))->toBeTrue();

        $manager->select($admin, 'arkada');
        expect(Cache::has($overrideKey))->toBeFalse();
    });

    it('logs an unknown theme id once per day', function (): void {
        Log::spy();
        Cache::flush();
        $registry = app(ThemeRegistry::class);

        $registry->findOrDefault('gone-theme');
        $registry->findOrDefault('gone-theme');

        Log::shouldHaveReceived('warning')->once();
    });
});
