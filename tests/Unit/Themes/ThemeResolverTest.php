<?php

declare(strict_types=1);

use App\Services\Themes\Color;
use App\Services\Themes\ContrastChecker;
use App\Services\Themes\FontFamily;
use App\Services\Themes\FontRegistry;
use App\Services\Themes\ResolvedTheme;
use App\Services\Themes\Theme;
use App\Services\Themes\ThemeCss;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use Illuminate\Support\Facades\Log;

function themeResolver(): ThemeResolver
{
    return app(ThemeResolver::class);
}

function resolveTheme(string $id, array $overrides = []): ResolvedTheme
{
    return themeResolver()->resolve(app(ThemeRegistry::class)->find($id), $overrides);
}

describe('registry (TH-01, TH-02)', function (): void {
    it('lists default first and 50 themes, groups them by shop type', function (): void {
        $registry = app(ThemeRegistry::class);

        expect(array_keys($registry->all())[0])->toBe('default')
            ->and($registry->all())->toHaveCount(51)
            ->and($registry->groups())->toHaveCount(10)
            ->and(array_sum(array_map('count', $registry->grouped())))->toBe(50)
            ->and($registry->find('volt')->name)->toBe('Вольт')
            ->and($registry->find('nope'))->toBeNull();
    });

    it('falls back to default for an unknown id and logs a warning (TH-02)', function (): void {
        Log::shouldReceive('warning')->once();

        expect(app(ThemeRegistry::class)->findOrDefault('missing')->id)->toBe('default');
    });

    it('gives every theme valid colors, known fonts and valid shape', function (): void {
        $fonts = app(FontRegistry::class);

        foreach (app(ThemeRegistry::class)->all() as $theme) {
            expect($theme->colors)->toHaveCount(12);

            foreach ($theme->colors as $color) {
                expect(Color::normalize($color))->toBe($color);
            }

            if ($theme->id !== 'default') {
                expect($fonts->find($theme->fonts['display']['family']))->not->toBeNull()
                    ->and($fonts->find($theme->fonts['body']['family']))->not->toBeNull()
                    ->and($fonts->find($theme->fonts['body']['family'])->displayOnly())->toBeFalse();
            }
        }
    });

    it('keeps resources/themes identical to docs/themes (TH-01)', function (): void {
        foreach (['themes.json', 'fonts.json'] as $file) {
            expect(file_get_contents(resource_path('themes/'.$file)))->toBe(file_get_contents(base_path('docs/themes/'.$file)));
        }
    });
});

describe('fonts (TH-18)', function (): void {
    it('has 60 families and every file exists on disk with its license', function (): void {
        $fonts = app(FontRegistry::class)->all();

        expect($fonts)->toHaveCount(60);

        foreach ($fonts as $font) {
            expect(public_path('fonts/'.$font->slug.'/LICENSE'))->toBeFile();

            foreach ($font->weights as $weight) {
                foreach (FontFamily::SUBSETS as $subset) {
                    expect($font->path($subset, $weight))->toBeFile();

                    if ($font->italic) {
                        expect($font->path($subset, $weight, true))->toBeFile();
                    }
                }
            }
        }
    });

    it('builds the slug and finds the nearest weight', function (): void {
        expect(FontRegistry::slug('M PLUS Rounded 1c'))->toBe('m-plus-rounded-1c')
            ->and(FontRegistry::slug('Golos Text'))->toBe('golos-text')
            ->and(app(FontRegistry::class)->find('Golos Text')->nearestWeight(650))->toBeIn([500, 700]);
    });

    it('offers display-only families for headings only (TH-20)', function (): void {
        $body = app(FontRegistry::class)->bodyChoices();

        expect($body)->not->toHaveKey('Alice')->and($body)->toHaveKey('Golos Text');
    });
});

describe('resolver (TH-05, TH-07)', function (): void {
    it('uses the theme values without overrides', function (): void {
        $theme = resolveTheme('volt');

        expect($theme->colors['bg'])->toBe('#F6F8FB')
            ->and($theme->colors['muted'])->toBe('#566174')
            ->and($theme->colors['onPrimary'])->toBe('#FFFFFF')
            ->and($theme->shape['radius'])->toBe(6)
            ->and($theme->fonts['display']['family'])->toBe('Golos Text')
            ->and($theme->fonts['body']['family'])->toBe('IBM Plex Sans')
            ->and($theme->overrides)->toBe([]);
    });

    it('derives muted, line and tile when ink or bg is overridden and keeps hero colors', function (): void {
        $theme = resolveTheme('volt', ['colors' => ['bg' => '#000000', 'ink' => '#FFFFFF']]);

        expect($theme->colors['muted'])->toBe(Color::mix('#FFFFFF', '#000000', ThemeResolver::MUTED_SHARE))
            ->and($theme->colors['line'])->toBe(Color::mix('#FFFFFF', '#000000', ThemeResolver::LINE_SHARE))
            ->and($theme->colors['tile'])->toBe(Color::mix('#FFFFFF', '#000000', ThemeResolver::TILE_SHARE))
            ->and($theme->colors['heroBg'])->toBe('#E6ECF7')
            ->and(Color::contrast($theme->colors['muted'], '#000000'))->toBeGreaterThan(4.5);
    });

    it('chooses black or white on primary and accent by contrast', function (): void {
        $light = resolveTheme('volt', ['colors' => ['primary' => '#FFE600', 'accent' => '#101010']]);
        $dark = resolveTheme('volt', ['colors' => ['primary' => '#101010']]);

        expect($light->colors['onPrimary'])->toBe('#000000')
            ->and($light->colors['onAccent'])->toBe('#FFFFFF')
            ->and($dark->colors['onPrimary'])->toBe('#FFFFFF');
    });

    it('does not touch derived colors when only the surface changes', function (): void {
        $theme = resolveTheme('volt', ['colors' => ['surface' => '#FAFAFA']]);

        expect($theme->colors['surface'])->toBe('#FAFAFA')->and($theme->colors['muted'])->toBe('#566174');
    });

    it('overrides fonts and takes the nearest weight of the new family', function (): void {
        $theme = resolveTheme('volt', ['fonts' => ['display' => 'Alice', 'body' => 'Golos Text']]);

        expect($theme->fonts['display']['family'])->toBe('Alice')
            ->and($theme->fonts['display']['weight'])->toBe(400)
            ->and($theme->fonts['body']['family'])->toBe('Golos Text');
    });

    it('supports the italic display of Kross and system fonts of default', function (): void {
        expect(resolveTheme('kross')->fonts['display']['italic'])->toBeTrue()
            ->and(resolveTheme('default')->fonts['display']['family'])->toBeNull();
    });
});

describe('contrast (TH-08)', function (): void {
    it('computes WCAG ratios', function (): void {
        expect(Color::contrast('#000000', '#FFFFFF'))->toEqualWithDelta(21.0, 0.01)
            ->and(Color::contrast('#777777', '#FFFFFF'))->toEqualWithDelta(4.48, 0.02);
    });

    it('lists the pairs below 4.5:1', function (): void {
        $colors = ['ink' => '#888888', 'bg' => '#FFFFFF', 'surface' => '#999999', 'onPrimary' => '#FFFFFF', 'primary' => '#FFFFFF', 'onAccent' => '#000000', 'accent' => '#FFFFFF'];
        $pairs = array_column((new ContrastChecker)->failures($colors), 'pair');

        expect($pairs)->toBe(['ink_bg', 'ink_surface', 'onPrimary_primary']);
    });

    it('reports no warning for a clean theme', function (): void {
        expect(resolveTheme('volt')->warnings)->toBe([]);
    });
});

describe('sanitizing (TH-09)', function (): void {
    it('drops invalid colors and unknown fonts', function (): void {
        $clean = themeResolver()->sanitizeOverrides([
            'colors' => ['bg' => 'red;}</style><script>alert(1)</script>', 'ink' => 'url(x)', 'primary' => '#12345', 'accent' => '#abc', 'surface' => '#00FF00', 'muted' => '#123456'],
            'fonts' => ['display' => 'Evil";}</style>', 'body' => 'Alice'],
        ]);

        expect($clean)->toBe(['colors' => ['surface' => '#00FF00', 'accent' => '#aabbcc']]);
    });

    it('ignores non-array overrides', function (): void {
        expect(themeResolver()->sanitizeOverrides('<script>'))->toBe([]);
    });

    it('never prints injected values into the css', function (): void {
        $resolved = resolveTheme('volt', ['colors' => ['bg' => 'red;}</style><script>'], 'fonts' => ['display' => 'x;}']]);
        $css = app(ThemeCss::class)->assets($resolved)['css'];

        expect($css)->not->toContain('<')->and($css)->not->toContain('script')->and($css)->toContain('--shop-bg: #f6f8fb;');
    });

    it('clamps numbers coming from a theme file', function (): void {
        $registry = app(ThemeRegistry::class);
        $base = $registry->find('volt');
        $theme = new Theme('x', 99, 'X', null, null, null, null, $base->colors, ['radius' => 9999, 'buttonRadius' => -5, 'borderWidth' => 50, 'surface' => 'evil'], ['display' => ['family' => 'Golos Text', 'weight' => 5000, 'scale' => 99, 'case' => 'x;', 'letterSpacing' => 'red}'], 'body' => ['family' => 'IBM Plex Sans']], [], []);
        $resolved = themeResolver()->resolve($theme);

        expect($resolved->shape)->toBe(['radius' => 999, 'buttonRadius' => 0, 'borderWidth' => 8, 'surface' => 'line'])
            ->and($resolved->fonts['display']['weight'])->toBeIn([800])
            ->and($resolved->fonts['display']['scale'])->toBe(2.0)
            ->and($resolved->fonts['display']['case'])->toBe('none')
            ->and($resolved->fonts['display']['letterSpacing'])->toBeNull();
    });
});

describe('css and font loading (TH-05, TH-19)', function (): void {
    it('prints every token of the contract', function (): void {
        $css = app(ThemeCss::class)->assets(resolveTheme('volt'))['css'];

        foreach (['bg', 'surface', 'ink', 'muted', 'line', 'primary', 'on-primary', 'accent', 'on-accent', 'hero-bg', 'hero-ink', 'tile', 'radius', 'radius-btn', 'border-w', 'font-display', 'font-body', 'font-mono', 'display-weight', 'display-case', 'display-scale'] as $token) {
            expect($css)->toContain('--shop-'.$token.':');
        }

        expect($css)->toContain('--shop-radius: 6px;')->and($css)->toContain('--shop-font-display: "Golos Text",');
    });

    it('loads only the fonts of the theme and its overrides', function (): void {
        $files = app(ThemeCss::class)->assets(resolveTheme('volt'))['files'];

        expect(implode(' ', $files))->toContain('golos-text-cyrillic-700-normal')
            ->and(implode(' ', $files))->toContain('ibm-plex-sans-latin-400-normal')
            ->and(implode(' ', $files))->toContain('jetbrains-mono')
            ->and(implode(' ', $files))->not->toContain('manrope');

        $changed = app(ThemeCss::class)->assets(resolveTheme('volt', ['fonts' => ['display' => 'Manrope']]))['files'];
        expect(implode(' ', $changed))->toContain('manrope')->and(implode(' ', $changed))->not->toContain('golos-text');
    });

    it('uses no external urls and swaps fonts', function (): void {
        $css = app(ThemeCss::class)->assets(resolveTheme('volt'))['css'];

        expect($css)->not->toContain('http')->and($css)->toContain('font-display:swap')->and($css)->toContain('unicode-range:U+0301');
    });

    it('preloads the display and body fonts', function (): void {
        expect(app(ThemeCss::class)->assets(resolveTheme('volt'))['preload'])->toHaveCount(2);
    });

    it('prints the italic face for Kross', function (): void {
        expect(app(ThemeCss::class)->assets(resolveTheme('kross'))['css'])->toContain('font-style:italic');
    });
});
