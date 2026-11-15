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

function useThemeForContrast(string $id): void
{
    $settings = app(AppearanceSettings::class);
    $settings->theme_id = $id;
    $settings->theme_overrides = [];
    $settings->save();
}

function themeCssOf(string $id): string
{
    $registry = app(ThemeRegistry::class);

    return app(ThemeCss::class)->assets(app(ThemeResolver::class)->resolve($registry->find($id) ?? $registry->default()))['css'];
}

/** @return list<string> */
function themeIds(): array
{
    return array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/themes/themes.json'), true)['themes'], 'id');
}

describe('focus ring (TH-16, gate 26a)', function (): void {
    it('prints no focus rule for the default theme', function (): void {
        expect(themeCssOf('default'))->not->toContain('--shop-focus');
    });

    it('uses the text color where the primary does not reach 3:1 on the page, and keeps the band readable', function (): void {
        $theme = app(ThemeRegistry::class)->find('opalubka');
        $css = themeCssOf('opalubka');

        expect(Color::contrast($theme->colors['primary'], $theme->colors['bg']))->toBeLessThan(3.0)
            ->and($css)->toContain(':root{--shop-focus: '.strtolower($theme->colors['ink']).';}')
            ->and($css)->toContain('.hero-band{--shop-focus: '.strtolower($theme->colors['primary']).';}')
            ->and($css)->toContain('.focus-visible\:outline-primary:focus-visible{outline-color:var(--shop-focus)}');
    });

    it('gives every theme a focus color with at least 3:1 on the page and on the hero band', function (string $id): void {
        $theme = app(ThemeRegistry::class)->find($id);
        $css = themeCssOf($id);
        $c = $theme->colors;

        $page = preg_match('/:root\{--shop-focus: (#[0-9a-f]{6});\}/', $css, $m) === 1 ? $m[1] : $c['primary'];
        $band = preg_match('/\.hero-band\{--shop-focus: (#[0-9a-f]{6});\}/', $css, $m) === 1 ? $m[1] : $page;

        expect(Color::contrast($page, $c['bg']))->toBeGreaterThanOrEqual(3.0)
            ->and(Color::contrast($page, $c['surface']))->toBeGreaterThanOrEqual(3.0)
            ->and(Color::contrast($page, Color::mix($c['line'], $c['surface'], 0.23)))->toBeGreaterThanOrEqual(3.0)
            ->and(Color::contrast($band, $c['heroBg']))->toBeGreaterThanOrEqual(3.0);
    })->with(fn () => themeIds());
});

describe('text on the page (TH-08, gate 40)', function (): void {
    it('prints no text color rule for the default theme', function (): void {
        expect(themeCssOf('default'))->not->toContain('--shop-primary-text')
            ->not->toContain('--shop-success-text');
    });

    it('keeps the status and primary text colors at 4.5:1 on bg and surface in every theme', function (string $id): void {
        $theme = app(ThemeRegistry::class)->find($id);
        $css = themeCssOf($id);
        $c = $theme->colors;
        $colors = ['success' => '#007A55', 'warn-deep' => '#BB4D00', 'danger' => '#C10007', 'primary' => $c['primary']];

        foreach ($colors as $name => $fallback) {
            $color = preg_match('/--shop-'.$name.'-text: (#[0-9a-f]{6});/', $css, $m) === 1 ? $m[1] : $fallback;

            expect(Color::contrast($color, $c['bg']))->toBeGreaterThanOrEqual(4.5, "{$id}: {$name} on bg")
                ->and(Color::contrast($color, $c['surface']))->toBeGreaterThanOrEqual(4.5, "{$id}: {$name} on surface")
                ->and(Color::contrast($color, Color::mix($c['line'], $c['surface'], 0.23)))->toBeGreaterThanOrEqual(4.5, "{$id}: {$name} on surface-2");
        }
    })->with(fn () => themeIds());

    it('changes only the text utilities: fills, washes and labels keep their colors', function (): void {
        $css = themeCssOf('opalubka');
        $theme = app(ThemeRegistry::class)->find('opalubka');

        expect($css)->toContain('--shop-primary: '.strtolower($theme->colors['primary']).';')
            ->toContain('.text-primary:not([class^="bg-"]):not([class*=" bg-"])')
            ->toContain('.text-danger:not([class^="bg-"]):not([class*=" bg-"])')
            ->not->toContain('--shop-danger:')
            ->not->toContain('--shop-success:')
            ->not->toContain('.bg-primary');
    });
});

describe('fields and disabled controls (gate 40)', function (): void {
    it('marks the 50 themes with data-contrast and leaves the default theme as it was', function (): void {
        Category::factory()->create();

        useThemeForContrast('default');
        expect($this->get(route('home'))->assertOk()->getContent())->not->toContain('data-contrast');

        useThemeForContrast('volt');
        expect($this->get(route('home'))->assertOk()->getContent())->toMatch('/<html[^>]*data-contrast/');
    });

    it('draws the field border with the field-line token, gated by the attribute (css source)', function (): void {
        $css = (string) file_get_contents(resource_path('css/theme/surfaces.css'));

        expect($css)->toContain('--shop-field-line: var(--shop-muted);')
            ->toContain('html[data-contrast] :where(input')
            ->toContain('border-color: var(--shop-field-line);')
            ->toContain('html[data-contrast] :where([aria-disabled=\'true\'], button:disabled[data-action])');
    });

    it('has a muted color with at least 3:1 on bg and surface in every theme (the field edge)', function (string $id): void {
        $c = app(ThemeRegistry::class)->find($id)->colors;

        expect(Color::contrast($c['muted'], $c['bg']))->toBeGreaterThanOrEqual(3.0)
            ->and(Color::contrast($c['muted'], $c['surface']))->toBeGreaterThanOrEqual(3.0);
    })->with(fn () => themeIds());
});

describe('fixes from the preview check', function (): void {
    it('sets the shop name in the text color in the themes and in the primary color in default', function (): void {
        useThemeForContrast('default');
        $default = $this->get(route('home'))->assertOk()->getContent();
        useThemeForContrast('grafit');
        $grafit = $this->get(route('home'))->assertOk()->getContent();

        expect($default)->toMatch('/font-display text-lg font-semibold text-primary/')
            ->and($grafit)->toMatch('/font-display text-lg font-semibold text-ink/');
    });

    it('rounds photo tiles with the tile radius, never rounder than a card', function (): void {
        Product::factory()->create(['stock_qty' => 3]);
        $css = (string) file_get_contents(resource_path('css/app.css'));

        useThemeForContrast('korall');
        $html = $this->get(route('catalog.index'))->assertOk()->getContent();

        expect($css)->toContain('--radius-tile: min(var(--shop-radius-btn), var(--shop-radius));')
            ->and($html)->toContain('rounded-tile bg-tile');
    });

    it('sets the C4 characteristic lines in the monospace face only when the theme has one', function (): void {
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->create(['stock_qty' => 3]);

        useThemeForContrast('volt');
        expect($this->get(route('catalog.index'))->assertOk()->getContent())->toMatch('/class="[^"]*font-mono[^"]*" data-card-specs/');

        useThemeForContrast('garazh');
        expect($this->get(route('catalog.index'))->assertOk()->getContent())->not->toMatch('/class="[^"]*font-mono[^"]*" data-card-specs/');
    });
});
