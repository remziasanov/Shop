<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Themes\CssValue;
use App\Services\Themes\ResolvedTheme;
use App\Services\Themes\Theme;
use App\Services\Themes\ThemeCss;
use App\Services\Themes\ThemePreview;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\AppearanceSettings;

beforeEach(function (): void {
    $this->withoutVite();
});

function surfaceTheme(string $id): ResolvedTheme
{
    return app(ThemeResolver::class)->resolve(app(ThemeRegistry::class)->find($id));
}

function surfaceCss(string $id): string
{
    return app(ThemeCss::class)->assets(surfaceTheme($id))['css'];
}

function surfaceStaff(): User
{
    return User::factory()->withPermissions(['settings.appearance.update'])->create();
}

it('prints the surface of every theme on the html tag (TH-14)', function (): void {
    foreach (app(ThemeRegistry::class)->all() as $id => $theme) {
        expect(surfaceTheme($id)->htmlAttributes())->toContain('data-surface="'.$theme->shape['surface'].'"');
    }

    $settings = app(AppearanceSettings::class);
    $settings->theme_id = 'arkada';
    $settings->save();

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toMatch('/<html[^>]*data-surface="hard"[^>]*data-bw="2"/');
});

it('keeps the default theme free of switches except the surface (pixel-identical default)', function (): void {
    expect(surfaceTheme('default')->htmlAttributes())->toBe('data-surface="line"')
        ->and(surfaceCss('default'))->not->toContain('data-tile-index')->not->toContain('--shop-shadow-color')->not->toContain('--shop-bg-image');
});

it('emits surface tokens only for themes that have them, validated (TH-09, TH-14)', function (): void {
    expect(surfaceCss('arkada'))->toContain('--shop-shadow-color: #0c0612;')
        ->and(surfaceCss('akva'))->toContain('--shop-shadow-tint: rgba(10,110,158,0.16);')
        ->and(surfaceCss('karamel'))->toContain('--shop-press-color: #8e1b41;')
        ->and(surfaceCss('volt'))->not->toContain('--shop-shadow-color')->not->toContain('--shop-press-color');
});

it('emits tile rotation, patterns and page background only for themes with those extras (TH-14)', function (): void {
    $bato = surfaceCss('batonchik');
    $palitra = surfaceCss('palitra');

    expect($bato)->toContain('[data-tile-index="0"]')->toContain('--shop-tile:#ffb3c7')->toContain('[data-tile-index="4"]')
        ->and($palitra)->toContain('repeating-linear-gradient(90deg')
        ->and(surfaceCss('steklo'))->toContain('--shop-bg-image: radial-gradient(')
        ->and(surfaceTheme('steklo')->htmlAttributes())->toContain('data-bg-image')
        ->and(surfaceTheme('rosa')->htmlAttributes())->toContain('data-tile-shape="arch"')
        ->and(surfaceTheme('matcha')->htmlAttributes())->toContain('data-tile-shape="circle"')
        ->and(surfaceTheme('eko')->htmlAttributes())->toContain('data-dashed')
        ->and(surfaceTheme('galereya')->htmlAttributes())->toContain('data-crop-marks')
        ->and(surfaceCss('volt'))->not->toContain('data-tile-index')
        ->and(surfaceTheme('volt')->htmlAttributes())->not->toContain('data-tile-shape')->not->toContain('data-dashed');
});

it('sanitizes gradients and rgba colors strictly (TH-09)', function (): void {
    expect(CssValue::gradient('radial-gradient(#FFFFFF 2.5px, rgba(255,255,255,0) 3px) 0 0 / 28px 28px, #E8F3FF'))->not->toBeNull()
        ->and(CssValue::gradient('url(https://evil.test/x.png)'))->toBeNull()
        ->and(CssValue::gradient('linear-gradient(red;}</style>)'))->toBeNull()
        ->and(CssValue::gradient('linear-gradient(#fff, #000'))->toBeNull()
        ->and(CssValue::gradient('expression(alert(1))'))->toBeNull()
        ->and(CssValue::gradient('linear-gradient(#fff, "x")'))->toBeNull()
        ->and(CssValue::rgba('rgba(10,110,158,0.16)'))->toBe('rgba(10,110,158,0.16)')
        ->and(CssValue::rgba('rgba(300,0,0,1)'))->toBeNull()
        ->and(CssValue::rgba('rgba(0,0,0,0.5);color:red'))->toBeNull();
});

it('drops malicious extras and shape colors from a theme file (TH-09)', function (): void {
    $base = app(ThemeRegistry::class)->default();
    $theme = new Theme('x', 99, 'X', null, null, null, null, $base->colors, ['radius' => 8, 'buttonRadius' => 4, 'borderWidth' => 1, 'surface' => 'hard', 'shadowColor' => 'red;}', 'shadowTint' => 'rgba(0,0,0,1);x', 'pressColor' => '#12'], $base->fonts, $base->blocks, ['tileShape' => 'x"', 'tileColors' => ['#fff', 'url(x)'], 'tilePatterns' => ['url(x)'], 'backgroundImage' => 'url(x)', 'dashedFrames' => 'yes']);
    $resolved = app(ThemeResolver::class)->resolve($theme);

    expect($resolved->shape)->not->toHaveKeys(['shadowColor', 'shadowTint', 'pressColor'])
        ->and($resolved->extras)->toBe(['tileColors' => ['#ffffff']])
        ->and($resolved->htmlAttributes())->toBe('data-surface="hard"');
});

it('has the surface, state and reduced-motion rules in the shared CSS (TH-14, TH-16, TH-17)', function (): void {
    $css = (string) file_get_contents(resource_path('css/theme/surfaces.css'));

    foreach (['hard', 'soft', 'press', 'double', 'glass', 'clay', 'none'] as $surface) {
        expect($css)->toContain("html[data-surface='{$surface}']");
    }

    expect($css)->toContain('backdrop-filter')->toContain('@supports not')
        ->toContain(':focus-visible')->toContain(':active')->toContain(':is(:disabled, [aria-disabled=\'true\'])')
        ->toContain('[data-loading]')->toContain("[aria-busy='true']")->toContain("[aria-invalid='true']")
        ->toContain('@media (prefers-reduced-motion: reduce)')->toContain('animation: none !important')->toContain('transition: none !important');
});

it('carries the state classes on the key controls (TH-16)', function (): void {
    $cart = (string) file_get_contents(resource_path('views/livewire/storefront/add-to-cart.blade.php'));
    $field = (string) file_get_contents(resource_path('views/components/storefront/field.blade.php'));

    expect($cart)->toContain('hover:opacity-90')->toContain('focus-visible:outline')->toContain('disabled:opacity-60')->toContain('wire:loading.attr="disabled"')
        ->and($field)->toContain('aria-invalid:border-danger-solid')->toContain('focus-visible:outline');
});

it('puts the heading sizes under the theme scale (stage 21 checklist)', function (): void {
    $show = (string) file_get_contents(resource_path('views/storefront/product/show.blade.php'));
    $tier = (string) file_get_contents(resource_path('views/storefront/product/tier-table.blade.php'));

    expect($show)->toContain('<h2 class="text-lg font-semibold">')
        ->and($show)->not->toMatch('/<button[^>]*text-left text-lg/')
        ->and($tier)->toContain('class="font-body text-sm font-semibold"')
        ->and((string) file_get_contents(resource_path('views/storefront/partials/footer.blade.php')))->toContain('id="footer-menu" class="font-body')
        ->and((string) file_get_contents(resource_path('views/livewire/storefront/checkout-form.blade.php')))->toContain('<h3 class="font-body');
});

it('keeps the field hover and error borders outside the layers so the border utilities do not beat them (TH-16)', function (): void {
    $css = (string) file_get_contents(resource_path('css/theme/surfaces.css'));
    $layerEnd = strpos($css, "\n}\n\n/*\n * Field hover");

    // Unlayered: after the last `@layer` block, not inside `@layer components`.
    $hover = strpos($css, "[data-surface]:not([data-surface='line']) :where(input");
    $error = strpos($css, "\n:where(input:not([type='checkbox'], [type='radio']), select, textarea)[aria-invalid='true']");

    expect($layerEnd)->not->toBeFalse()
        ->and($hover)->toBeGreaterThan($layerEnd)
        ->and($error)->toBeGreaterThan($layerEnd)
        ->and(substr($css, 0, $layerEnd))->not->toContain("[aria-invalid='true'] {\n        border-color");
});

describe('temporary service theme debug-blocks (remove in stage 23)', function (): void {
    it('does not exist without the flag, even in a staff preview', function (): void {
        config()->set('shop.theme_debug', false);
        $registry = app(ThemeRegistry::class);

        expect($registry->findForPreview(ThemeRegistry::DEBUG_ID))->toBeNull()
            ->and(array_keys($registry->all()))->not->toContain(ThemeRegistry::DEBUG_ID);

        $html = $this->actingAs(surfaceStaff())->get(route('home', ['theme' => 'debug-blocks', 'blocks' => 'H3']))->assertOk()->getContent();

        expect($html)->not->toContain('data-theme-preview-bar');
    });

    it('is never in the gallery and never the saved theme, even with the flag', function (): void {
        config()->set('shop.theme_debug', true);

        expect(array_keys(app(ThemeRegistry::class)->all()))->not->toContain(ThemeRegistry::DEBUG_ID)
            ->and(app(ThemeRegistry::class)->has(ThemeRegistry::DEBUG_ID))->toBeFalse();

        $settings = app(AppearanceSettings::class);
        $settings->theme_id = ThemeRegistry::DEBUG_ID;
        $settings->save();

        $this->get(route('home', ['theme' => 'debug-blocks', 'blocks' => 'H3']))->assertOk()->assertDontSee('data-theme-preview-bar');
        expect(app(ThemePreview::class)->parseBlocks('H3'))->toBe(['header' => 'H3']);
    });

    it('opens for staff only and takes validated blocks from the query', function (): void {
        config()->set('shop.theme_debug', true);

        // A guest changes nothing.
        $this->get(route('home', ['theme' => 'debug-blocks', 'blocks' => 'H3']))->assertOk()->assertDontSee('data-theme-preview-bar');

        $this->actingAs(surfaceStaff())->get(route('home', ['theme' => 'debug-blocks', 'blocks' => 'H3,C5,G2,L3,X9,H9']))
            ->assertOk()->assertSee('data-theme-preview-bar', false)->assertSee('Отладка: блоки', false);

        expect(session(ThemePreview::BLOCKS_KEY))->toBe(['header' => 'H3', 'card' => 'C5', 'hero' => 'G2', 'listing' => 'L3']);

        $response = $this->get(route('home'))->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('hides Apply for the service theme, refuses it and keeps prev / next inside real themes', function (): void {
        config()->set('shop.theme_debug', true);
        $staff = surfaceStaff();

        $html = $this->actingAs($staff)->get(route('home', ['theme' => 'debug-blocks', 'blocks' => 'H3']))->assertOk()->getContent();
        expect($html)->toContain('data-theme-preview-bar')->and($html)->not->toContain('<button type="submit" data-apply>')->and($html)->not->toMatch('~\(\d+/\d+\)~');

        $this->post(route('theme.preview.apply'))->assertForbidden();

        $this->post(route('theme.preview.step'), ['direction' => 'next']);
        expect(session(ThemePreview::SESSION_KEY)['theme_id'])->not->toBe(ThemeRegistry::DEBUG_ID);
    });

    it('validates the variants against the allowed sets', function (): void {
        $previews = app(ThemePreview::class);

        expect($previews->parseBlocks('h2, c6 ,G4,l1'))->toBe(['header' => 'H2', 'card' => 'C6', 'hero' => 'G4', 'listing' => 'L1'])
            ->and($previews->parseBlocks('H7,C0,G5,L4,<script>'))->toBe([])
            ->and($previews->parseBlocks(str_repeat('H1,', 100)))->toBe(['header' => 'H1']);
    });
});
