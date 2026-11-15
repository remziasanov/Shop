<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Services\Themes\Color;
use App\Services\Themes\ThemeCss;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\AppearanceSettings;

/*
 * Stage 22 (TH-08, TH-14, TH-16, TH-33): the contrast rules of the 50 themes (Hallmark gates 26a and 40) and the
 * fixes found while checking the themes against the previews. The default theme keeps its approved look.
 */

beforeEach(function (): void {
    $this->withoutVite();
});

function stage22UseTheme(string $id): void
{
    $settings = app(AppearanceSettings::class);
    $settings->theme_id = $id;
    $settings->theme_overrides = [];
    $settings->save();
}

function stage22ThemeCss(string $id): string
{
    $registry = app(ThemeRegistry::class);

    return app(ThemeCss::class)->assets(app(ThemeResolver::class)->resolve($registry->find($id) ?? $registry->default()))['css'];
}

/** @return list<string> */
function stage22ThemeIds(): array
{
    return array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/themes/themes.json'), true)['themes'], 'id');
}

describe('focus ring (TH-16, gate 26a)', function (): void {
    it('prints no web focus rule for the default theme (only the html[data-tg] ones, D-188)', function (): void {
        expect(stage22ThemeCss('default'))->not->toContain(':root{--shop-focus');
    });

    it('uses the text color where the primary does not reach 3:1 on the page (G1: no hero band rule)', function (): void {
        $theme = app(ThemeRegistry::class)->find('opalubka');
        $css = stage22ThemeCss('opalubka');

        expect($theme->blocks['hero'])->toBe('G1')
            ->and(Color::contrast($theme->colors['primary'], $theme->colors['bg']))->toBeLessThan(3.0)
            ->and($css)->toContain(':root{--shop-focus: '.strtolower($theme->colors['ink']).';}')
            ->and($css)->not->toContain('.hero-band{')
            ->and($css)->toContain('.focus-visible\:outline-primary:focus-visible{outline-color:var(--shop-focus)}');
    });

    it('gives the G2 / G4 hero band its own ring when the primary does not read on heroBg', function (): void {
        $theme = app(ThemeRegistry::class)->find('stiker');
        $css = stage22ThemeCss('stiker');

        expect($theme->blocks['hero'])->toBe('G2')
            ->and(Color::contrast($theme->colors['primary'], $theme->colors['heroBg']))->toBeLessThan(3.0)
            ->and($css)->toContain('.hero-band{--shop-focus: '.strtolower($theme->colors['heroInk']).';}');
    });

    it('gives every theme a focus color with at least 3:1 on the page and on the hero band', function (string $id): void {
        $theme = app(ThemeRegistry::class)->find($id);
        $css = stage22ThemeCss($id);
        $c = $theme->colors;

        $page = preg_match('/:root\{--shop-focus: (#[0-9a-f]{6});\}/', $css, $m) === 1 ? $m[1] : $c['primary'];
        $band = preg_match('/\.hero-band\{--shop-focus: (#[0-9a-f]{6});\}/', $css, $m) === 1 ? $m[1] : $page;
        $hasBand = in_array($theme->blocks['hero'], ['G2', 'G4'], true);

        expect(Color::contrast($page, $c['bg']))->toBeGreaterThanOrEqual(3.0)
            ->and(Color::contrast($page, $c['surface']))->toBeGreaterThanOrEqual(3.0)
            ->and(Color::contrast($page, Color::mix($c['line'], $c['surface'], 0.23)))->toBeGreaterThanOrEqual(3.0)
            ->and(! $hasBand || Color::contrast($band, $c['heroBg']) >= 3.0)->toBeTrue()
            ->and($hasBand || ! str_contains($css, '.hero-band{'))->toBeTrue();
    })->with(fn () => stage22ThemeIds());
});

describe('text on the page (TH-08, gate 40)', function (): void {
    it('prints no web text color rule for the default theme (only the html[data-tg] ones, D-188)', function (): void {
        expect(stage22ThemeCss('default'))->not->toContain(':root{--shop-primary-text')
            ->not->toContain(':root{--shop-success-text');
    });

    it('keeps the status and primary text colors at 4.5:1 on bg and surface in every theme', function (string $id): void {
        $theme = app(ThemeRegistry::class)->find($id);
        $css = stage22ThemeCss($id);
        $c = $theme->colors;
        $colors = ['success' => '#007A55', 'warn-deep' => '#BB4D00', 'danger' => '#C10007', 'primary' => $c['primary']];

        foreach ($colors as $name => $fallback) {
            $color = preg_match('/--shop-'.$name.'-text: (#[0-9a-f]{6});/', $css, $m) === 1 ? $m[1] : $fallback;

            expect(Color::contrast($color, $c['bg']))->toBeGreaterThanOrEqual(4.5, "{$id}: {$name} on bg")
                ->and(Color::contrast($color, $c['surface']))->toBeGreaterThanOrEqual(4.5, "{$id}: {$name} on surface")
                ->and(Color::contrast($color, Color::mix($c['line'], $c['surface'], 0.23)))->toBeGreaterThanOrEqual(4.5, "{$id}: {$name} on surface-2");
        }
    })->with(fn () => stage22ThemeIds());

    it('keeps the hue of the status colors on a dark page: a lighter color of the same tone, not a mix with the text (TD-35)', function (string $id): void {
        $css = stage22ThemeCss($id);
        $channels = static fn (string $name): array => array_map('hexdec', str_split(substr((string) (preg_match('/--shop-'.$name.'-text: (#[0-9a-f]{6});/', $css, $m) === 1 ? $m[1] : ''), 1), 2));

        [$sr, $sg, $sb] = $channels('success');
        [$wr, $wg, $wb] = $channels('warn-deep');
        [$dr, $dg, $db] = $channels('danger');

        expect($sg)->toBeGreaterThan($sr)->and($sg)->toBeGreaterThan($sb)       // green stays green
            ->and($wr)->toBeGreaterThan($wb)->and($wr)->toBeGreaterThanOrEqual($wg)  // orange stays warm
            ->and($dr)->toBeGreaterThan($dg)->and($dr)->toBeGreaterThan($db);     // red stays red
    })->with(['skviz', 'retro', 'chertyozh', 'arkada', 'grafit', 'nuar', 'garazh', 'hygge', 'terminal', 'ambra']);

    it('changes only the text utilities: fills, washes and labels keep their colors', function (): void {
        $css = stage22ThemeCss('opalubka');
        $theme = app(ThemeRegistry::class)->find('opalubka');

        expect($css)->toContain('--shop-primary: '.strtolower($theme->colors['primary']).';')
            ->toContain('.text-primary:not([class^="bg-"]):not([class*=" bg-"])')
            ->toContain('.text-danger:not([class^="bg-"]):not([class*=" bg-"]){color:var(--shop-danger-text)}@media (hover:hover){.hover\\:text-danger:hover{')
            ->toContain('.\\[\\&_a\\]\\:text-primary a')
            ->not->toContain('--shop-danger:')
            ->not->toContain('--shop-success:')
            ->not->toContain('.bg-primary');
    });
});

describe('fields and disabled controls (gate 40)', function (): void {
    it('marks the 50 themes with data-contrast and leaves the default theme as it was', function (): void {
        Category::factory()->create();

        stage22UseTheme('default');
        expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-contrast');

        stage22UseTheme('volt');
        expect($this->get(route('home'))->assertOk()->getContent())->toMatch('/<html[^>]*data-contrast/');
    });

    it('draws the field border with the field-line token, gated by the attribute (css source)', function (): void {
        $css = (string) file_get_contents(resource_path('css/theme/surfaces.css'));

        expect($css)->toContain('--shop-field-line: var(--shop-muted);')
            ->toContain('html[data-contrast] :where(input')
            ->toContain('border-color: var(--shop-field-line);')
            ->toContain("html[data-contrast] :where([aria-disabled='true'], button:disabled[data-action='unavailable']):not(.bg-primary, .bg-accent)")
            // A busy purchase button («В корзину» while its request runs) keeps the text color of its fill.
            ->not->toContain('button:disabled[data-action])');
    });

    it('has a muted color with at least 3:1 on bg and surface in every theme (the field edge)', function (string $id): void {
        $c = app(ThemeRegistry::class)->find($id)->colors;

        expect(Color::contrast($c['muted'], $c['bg']))->toBeGreaterThanOrEqual(3.0)
            ->and(Color::contrast($c['muted'], $c['surface']))->toBeGreaterThanOrEqual(3.0);
    })->with(fn () => stage22ThemeIds());
});

describe('fixes from the preview check', function (): void {
    it('sets the shop name in the text color in the themes and in the primary color in default', function (): void {
        stage22UseTheme('default');
        $default = $this->get(route('home'))->assertOk()->getContent();
        stage22UseTheme('grafit');
        $grafit = $this->get(route('home'))->assertOk()->getContent();

        expect($default)->toMatch('/font-display text-lg font-semibold text-primary/')
            ->and($grafit)->toMatch('/font-display text-lg font-semibold text-ink/');
    });

    it('rounds photo tiles with the tile radius, never rounder than a card', function (): void {
        Product::factory()->create(['stock_qty' => 3]);
        $css = (string) file_get_contents(resource_path('css/app.css'));

        stage22UseTheme('korall');
        $html = $this->get(route('catalog.index'))->assertOk()->getContent();

        expect($css)->toContain('--radius-tile: min(var(--shop-radius-btn), var(--shop-radius));')
            ->and($html)->toContain('rounded-tile bg-tile');
    });

    it('sets the C6 cell number in the text face when the theme has no monospace face (as in the previews)', function (): void {
        Product::factory()->create(['stock_qty' => 3]);

        stage22UseTheme('gazeta');
        expect(app(ThemeRegistry::class)->find('gazeta')->fonts['mono']['family'] ?? null)->toBeNull()
            ->and($this->get(route('catalog.index'))->assertOk()->getContent())
            ->toMatch('/<span\b[^>]*\bdata-card-number/')->not->toMatch('/<span class=""\s+data-card-number/')->not->toMatch('/<span\b[^>]*\bclass="[^"]*\bfont-mono\b[^"]*"[^>]*\bdata-card-number|<span\b[^>]*\bdata-card-number[^>]*\bclass="[^"]*\bfont-mono\b/');
    });

    it('sets the C4 characteristic lines in the monospace face only when the theme has one', function (): void {
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->create(['stock_qty' => 3]);

        stage22UseTheme('volt');
        expect($this->get(route('catalog.index'))->assertOk()->getContent())->toMatch('/class="[^"]*font-mono[^"]*" data-card-specs/');

        stage22UseTheme('garazh');
        expect($this->get(route('catalog.index'))->assertOk()->getContent())->not->toMatch('/class="[^"]*font-mono[^"]*" data-card-specs/');
    });
});

describe('layout fixes from the preview check (D-161)', function (): void {
    it('renders similar products through card-grid, with the classes the block had before in default', function (): void {
        $category = Category::factory()->create();
        $products = Product::factory()->count(3)->inCategory($category)->create(['stock_qty' => 3]);

        stage22UseTheme('default');
        $default = $this->get(route('product.show', $products[0]->slug))->assertOk()->getContent();
        stage22UseTheme('prays');
        $prays = $this->get(route('product.show', $products[0]->slug))->assertOk()->getContent();

        expect($default)->toContain('class="grid grid-cols-2 gap-3 md:gap-4 lg:grid-cols-4" data-card-grid="C1"')
            ->and($prays)->toContain('data-card-grid="C5"');
    });

    // The C3 photo radius, the C4 / C6 wrap and the G2 / G3 fixes are checked on the rendered pages in ThemeRenderedChecksTest (D-190).
});
