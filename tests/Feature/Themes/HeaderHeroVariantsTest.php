<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Services\Home\HomePageRenderer;
use App\Services\Themes\BlockExtras;
use App\Services\Themes\ResolvedTheme;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\AppearanceSettings;
use App\Settings\CustomerSettings;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/home.php';

beforeEach(function (): void {
    $this->withoutVite();
    Cache::flush();
});

function activateTheme(string $id): void
{
    $settings = app(AppearanceSettings::class);
    $settings->theme_id = $id;
    $settings->theme_overrides = [];
    $settings->save();
}

function heroBlocks(): void
{
    Category::factory()->count(5)->create();
    storeHomeBlocks([
        homeBlock('slider', ['slides' => [
            ['title' => 'Первый слайд', 'subtitle' => 'Подзаголовок', 'button_text' => 'Смотреть', 'button_link' => '/catalog', 'image' => bannerImage('a')],
            ['title' => 'Второй слайд', 'image' => bannerImage('b')],
        ]]),
        homeBlock('categories', ['mode' => 'auto', 'max_count' => 8, 'title' => 'Разделы']),
    ]);
}

describe('header variants (TH-10)', function (): void {
    it('renders each variant for a theme that uses it', function (string $theme, string $variant): void {
        Category::factory()->create(['name' => 'Верхняя']);
        activateTheme($theme);

        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->toContain('data-header="'.$variant.'"')
            ->and($html)->toContain('id="header-search"')
            ->and($html)->toContain('aria-controls="mobile-menu"')
            ->and($html)->toContain('data-cart-count');
    })->with([['klassika', 'H1'], ['grafit', 'H2'], ['volt', 'H3'], ['pudra', 'H4']]);

    it('keeps the search field under the row on phones for H1 and H3 only', function (): void {
        activateTheme('klassika');
        expect($this->get(route('home'))->getContent())->toContain('relative order-last w-full md:order-none')->not->toContain('data-search-panel');

        activateTheme('volt');
        expect($this->get(route('home'))->getContent())->toContain('relative order-last w-full md:order-none')->not->toContain('data-search-panel');

        foreach (['grafit', 'pudra'] as $theme) {
            activateTheme($theme);
            $html = $this->get(route('home'))->getContent();
            // The collapsed panel stays focusable (bottom bar «Поиск»), the toggle is a link without JavaScript, hidden below md.
            expect($html)->toContain('data-search-panel')->and($html)->toContain('data-collapsed')
                ->and($html)->toMatch('~<a href="[^"]*/search"[^>]*max-md:hidden[^>]*data-search-toggle~');
        }
    });

    it('H3 has the utility strip with the footer menu and the Каталог disclosure', function (): void {
        $root = Category::factory()->create(['name' => 'Раздел А']);
        Category::factory()->childOf($root)->create(['name' => 'Подраздел Б']);
        activateTheme('volt');

        $html = $this->get(route('home'))->getContent();

        expect($html)->toContain('data-header-catalog')->and($html)->toContain('<summary')->and($html)->toContain('Подраздел Б');
    });

    it('the mobile menu, contacts and install block are the same in every variant', function (string $theme): void {
        Category::factory()->create();
        activateTheme($theme);

        $html = $this->get(route('home'))->getContent();

        expect($html)->toContain('id="mobile-menu"')->and($html)->toContain('data-mobile-install');
    })->with(['klassika', 'grafit', 'volt', 'pudra']);

    it('shows the login link per customer settings in every variant', function (string $theme): void {
        $settings = app(CustomerSettings::class);
        $settings->registration_enabled = true;
        $settings->save();
        activateTheme($theme);

        expect($this->get(route('home'))->getContent())->toContain('data-account-login');

        $settings->registration_enabled = false;
        $settings->save();

        expect($this->get(route('home'))->getContent())->not->toContain('data-account-login');
    })->with(['klassika', 'grafit', 'volt', 'pudra']);

    it('draws the headerRule extra under the header (gazeta) and nowhere else', function (): void {
        activateTheme('gazeta');
        expect($this->get(route('home'))->getContent())->toContain('style="border-bottom:4px double #1F1B16"');

        activateTheme('klassika');
        expect($this->get(route('home'))->getContent())->not->toContain('border-bottom:4px');
    });

    it('uses the display font for the logo text', function (): void {
        activateTheme('klassika');

        expect($this->get(route('home'))->getContent())->toMatch('~font-display[^"]*text-primary~');
    });
});

describe('hero variants (TH-12)', function (): void {
    it('renders each variant from the same blocks of the home constructor', function (string $theme, string $hero): void {
        heroBlocks();
        activateTheme($theme);

        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->toContain('data-hero="'.$hero.'"')
            ->and($html)->toContain('Первый слайд')->and($html)->toContain('Второй слайд')
            ->and($html)->toContain('data-hero-slide');
    })->with([['korall', 'G1'], ['grafit', 'G2'], ['volt', 'G3'], ['pudra', 'G4']]);

    it('G2 and G4 sit on the hero band, G1 and G3 do not', function (): void {
        heroBlocks();

        foreach (['grafit' => true, 'pudra' => true, 'korall' => false, 'volt' => false] as $theme => $band) {
            activateTheme($theme);
            $html = $this->get(route('home'))->getContent();
            expect(str_contains($html, 'hero-band-inner'))->toBe($band);
        }
    });

    it('G3 merges the slider with the first three categories and lists the rest as usual tiles', function (): void {
        heroBlocks();
        activateTheme('volt');

        $html = $this->get(route('home'))->getContent();

        expect($html)->toContain('data-hero-bento')
            ->and(substr_count($html, 'data-tile-index='))->toBe(3)
            ->and(substr_count($html, 'class="hero-bento-main'))->toBe(1)
            ->and($html)->toContain('data-bento-title>Разделы</h2>');
    });

    it('G3 degrades to the plain slide without categories and G2 without text to an image', function (): void {
        storeHomeBlocks([homeBlock('banner', ['image' => bannerImage('a')])]);
        activateTheme('volt');
        $html = $this->get(route('home'))->assertOk()->getContent();
        expect($html)->toContain('data-hero="G3"')->not->toContain('data-hero-bento');

        activateTheme('grafit');
        $html = $this->get(route('home'))->assertOk()->getContent();
        expect($html)->toContain('data-hero="G2"')->toContain('home/a-1600.webp');
    });

    it('renders the home page without any hero block for every variant', function (string $theme): void {
        Product::factory()->count(2)->create();
        activateTheme($theme);

        $this->get(route('home'))->assertOk()->assertDontSee('data-hero-slide', false);
    })->with(['korall', 'grafit', 'volt', 'pudra']);

    it('applies heroButton and heroImage extras to the hero only', function (): void {
        storeHomeBlocks([homeBlock('banner', ['title' => 'Кнопка', 'button_text' => 'Go', 'button_link' => '/catalog', 'image' => bannerImage('a')])]);

        activateTheme('markirovka');
        $html = $this->get(route('home'))->getContent();
        expect($html)->toContain('--shop-hero-btn-bg:#FFB000;--shop-hero-btn-ink:#111A2B;')->and($html)->toContain('hero-btn');

        activateTheme('komiks');
        expect($this->get(route('home'))->getContent())->toContain('--shop-hero-image:radial-gradient(')->and($this->get(route('home'))->getContent())->toContain('hero-pattern');

        activateTheme('default');
        $html = $this->get(route('home'))->getContent();
        expect($html)->not->toContain('--shop-hero-btn-bg')->not->toContain('hero-pattern');
    });

    it('homeGridColumns changes the home product grid of market only', function (): void {
        Product::factory()->count(3)->inCategory(Category::factory()->create())->create(['is_new' => true]);
        storeHomeBlocks([homeBlock('products', ['source' => 'new', 'limit' => 6])]);

        activateTheme('market');
        expect($this->get(route('home'))->getContent())->toContain('lg:grid-cols-5');

        activateTheme('klassika');
        expect($this->get(route('home'))->getContent())->not->toContain('lg:grid-cols-5')->toContain('lg:grid-cols-4');
    });

    it('keeps the home cache apart per theme (TH-12)', function (): void {
        heroBlocks();
        activateTheme('grafit');
        $grafit = HomePageRenderer::cacheKey();
        $this->get(route('home'));
        activateTheme('volt');
        $volt = HomePageRenderer::cacheKey();

        expect($grafit)->not->toBe($volt)->and(Cache::has($grafit))->toBeTrue();
        expect($this->get(route('home'))->getContent())->toContain('data-hero="G3"')->not->toContain('data-hero="G2"');
    });
});

describe('default theme is unchanged', function (): void {
    it('renders H1 and G1 with the markup of the pre-theme storefront', function (): void {
        heroBlocks();
        $html = $this->get(route('home'))->assertOk()->getContent();

        expect($html)->toContain('data-header="H1"')->and($html)->toContain('data-hero="G1"')
            ->and($html)->toContain('<section class="mb-8"')
            ->and($html)->toContain('flex snap-x snap-mandatory overflow-x-auto')
            ->and($html)->toContain('absolute inset-x-0 bottom-0 bg-gradient-to-t from-scrim/70 to-transparent')
            ->and($html)->toContain('mx-auto flex w-full max-w-7xl flex-wrap items-center gap-3 px-4 py-3')
            ->and($html)->toContain('hidden border-t border-hairline md:block')
            ->and($html)->not->toContain('hero-band')->and($html)->not->toContain('data-header-catalog');
    });
});

describe('BlockExtras', function (): void {
    it('falls back to H1 / G1 and ignores malformed extras', function (): void {
        $registry = app(ThemeRegistry::class);
        $resolver = app(ThemeResolver::class);
        $theme = $resolver->resolve($registry->default());

        expect(BlockExtras::header(null))->toBe('H1')->and(BlockExtras::hero(null))->toBe('G1')
            ->and(BlockExtras::heroStyle(null))->toBe('')->and(BlockExtras::headerRule(null))->toBeNull()
            ->and(BlockExtras::homeGridColumns(null))->toBeNull();

        $bad = new ResolvedTheme(
            id: 'x', name: 'x', colors: $theme->colors, shape: $theme->shape, fonts: $theme->fonts,
            blocks: ['header' => 'H9', 'hero' => 'G0'],
            extras: ['heroButton' => ['bg' => 'red;}', 'ink' => '#000000'], 'heroImage' => 'url(javascript:alert(1))', 'headerRule' => '1px solid red;x', 'homeGridColumns' => 99],
            overrides: [], warnings: [],
        );

        expect(BlockExtras::header($bad))->toBe('H1')->and(BlockExtras::hero($bad))->toBe('G1')
            ->and(BlockExtras::heroStyle($bad))->toBe('')->and(BlockExtras::headerRule($bad))->toBeNull()
            ->and(BlockExtras::homeGridColumns($bad))->toBeNull();
    });
});

describe('hero extras validation (TH-09)', function (): void {
    $payloads = [
        'uppercase url' => 'linear-gradient(red,blue),URL(//evil.example/a.png)',
        'url' => 'url(https://evil.example/a.png)',
        'image-set' => 'image-set(linear-gradient(red,blue) 1x)',
        'expression' => 'expression(alert(1))',
        'css escape' => 'linear-gradient(red,blue),\\75 rl(//evil.example/a.png)',
        'declaration break' => 'linear-gradient(red,blue);}body{background:red',
    ];

    foreach ($payloads as $label => $payload) {
        it('rejects a hero image with '.$label, function () use ($payload): void {
            $resolver = app(ThemeResolver::class);
            $theme = $resolver->resolve(app(ThemeRegistry::class)->default());
            $method = new ReflectionMethod($resolver, 'extras');
            $method->setAccessible(true);
            $clean = $method->invoke($resolver, ['heroImage' => $payload, 'heroButton' => ['bg' => $payload, 'ink' => '#000000'], 'headerRule' => $payload]);

            expect($clean)->not->toHaveKeys(['heroImage', 'heroButton', 'headerRule']);

            $bad = new ResolvedTheme(
                id: 'x', name: 'x', colors: $theme->colors, shape: $theme->shape, fonts: $theme->fonts,
                blocks: [], extras: ['heroImage' => $payload], overrides: [], warnings: [],
            );

            expect(BlockExtras::heroStyle($bad))->toBe('');
        });
    }

    it('keeps every valid hero and header extra of the shipped themes', function (): void {
        $resolver = app(ThemeResolver::class);
        $method = new ReflectionMethod($resolver, 'extras');
        $method->setAccessible(true);
        $themes = json_decode((string) file_get_contents(resource_path('themes/themes.json')), true);
        $checked = 0;

        foreach ($themes['themes'] ?? $themes as $entry) {
            $extras = $entry['extras'] ?? [];

            if (! is_array($extras)) {
                continue;
            }

            $clean = $method->invoke($resolver, $extras);

            foreach (['heroImage', 'heroButton', 'headerRule'] as $key) {
                if (isset($extras[$key])) {
                    $checked++;
                    expect($clean)->toHaveKey($key);
                }
            }
        }

        expect($checked)->toBeGreaterThan(4);
    });
});

describe('theme preview bar on a phone', function (): void {
    it('collapses to at most two rows below 640 px', function (): void {
        // The bar is rendered only in a staff preview session; its markup is checked at the source.
        $view = (string) file_get_contents(resource_path('views/storefront/partials/theme-preview-bar.blade.php'));

        expect($view)->toContain('@media (max-width:640px)')->and($view)->toContain('data-theme-preview-title');
    });
});
