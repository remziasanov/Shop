<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_miniapp.php';

use App\Models\Product;
use App\Models\TelegramAccount;
use App\Services\Themes\Color;
use App\Services\Themes\ThemeCss;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\AppearanceSettings;

/*
 * D-188 (stage 22-23 review, finding 1; TH-15, TH-16): in the Telegram Mini App the client replaces the page, text and line
 * colors (telegram.js applyTheme), so the readable text and focus colors of a theme must follow the live tokens instead of
 * staying fixed hex values worked out against the palette of the website.
 */

beforeEach(function (): void {
    $this->withoutVite();
    connectTelegramBot(['miniapp_enabled' => true]);
});

/** Telegram's own light and dark schemes: what applyTheme() sets on <html> (text, page, section). */
function tgSchemes(): array
{
    return [
        'light' => ['ink' => '#000000', 'backs' => ['#ffffff', '#efeff3'], 'rule' => 'html\[data-tg\]'],
        'dark' => ['ink' => '#ffffff', 'backs' => ['#212121', '#2c2c2c'], 'rule' => 'html\[data-tg\]\[data-tg-scheme="dark"\]'],
    ];
}

function tgThemeCss(string $id): string
{
    $registry = app(ThemeRegistry::class);

    return app(ThemeCss::class)->assets(app(ThemeResolver::class)->resolve($registry->find($id) ?? $registry->default()))['css'];
}

/** Declarations of a rule printed for Telegram, as variable => value. @return array<string, string> */
function tgDeclarations(string $css, string $rule): array
{
    if (preg_match('/'.$rule.'\{([^}]*)\}/', $css, $m) !== 1) {
        return [];
    }

    $out = [];

    foreach (array_filter(explode(';', $m[1])) as $declaration) {
        [$name, $value] = array_map('trim', explode(':', $declaration, 2));
        $out[$name] = $value;
    }

    return $out;
}

/** The color a value resolves to under the live tokens of a Telegram scheme (text color `ink`, theme primary `primary`). */
function tgResolve(string $value, string $ink, string $primary): string
{
    $value = str_replace(['var(--shop-ink)', 'var(--shop-primary)'], [$ink, $primary], $value);

    if (preg_match('/^color-mix\(in srgb, (#[0-9a-f]{6}) (\d+)%, (#[0-9a-f]{6})\)$/i', $value, $m) === 1) {
        return Color::mix($m[1], $m[3], (int) $m[2] / 100);
    }

    return $value;
}

/** The effective value of a variable in a Telegram scheme: the dark rule, then the light one, then the website value. */
function tgEffective(string $css, string $scheme, string $name, string $fallback, string $primary): string
{
    $schemes = tgSchemes();
    $rules = $scheme === 'dark' ? [$schemes['dark']['rule'], $schemes['light']['rule']] : [$schemes['light']['rule']];

    foreach ($rules as $rule) {
        $declarations = tgDeclarations($css, $rule);

        if (isset($declarations[$name])) {
            return tgResolve($declarations[$name], $scheme === 'dark' ? '#ffffff' : '#000000', $primary);
        }
    }

    return preg_match('/:root\{'.preg_quote($name, '/').': (#[0-9a-f]{6});\}/', $css, $m) === 1 ? $m[1] : $fallback;
}

function tgThemeIds(): array
{
    return array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/themes/themes.json'), true)['themes'], 'id');
}

it('prints no Telegram rules for the default theme and the Telegram rules for the themes with a contrast override', function (): void {
    expect(tgThemeCss('default'))->not->toContain('data-tg');

    $css = tgThemeCss('opalubka');

    expect($css)->toContain('html[data-tg]{')->toContain('html[data-tg][data-tg-scheme="dark"]{')
        ->and($css)->toContain('--shop-focus: var(--shop-ink)')
        ->and($css)->toContain('color-mix(in srgb, var(--shop-primary) ')
        ->and($css)->toContain('html[data-tg] .text-primary:not([class^="bg-"]):not([class*=" bg-"])');
});

it('serves the Telegram rules in the Mini App page together with the Telegram layout attributes', function (): void {
    $account = TelegramAccount::factory()->create(['telegram_user_id' => 777]);
    Product::factory()->create(['stock_qty' => 3]);
    $settings = app(AppearanceSettings::class);
    $settings->theme_id = 'opalubka';
    $settings->theme_overrides = [];
    $settings->save();

    $html = $this->withSession(tgSession($account))->get('/')->assertOk()->getContent();

    expect($html)->toMatch('/<html\b[^>]*\bdata-tg\b/')
        ->and($html)->toContain('html[data-tg][data-tg-scheme="dark"]{')
        ->and($html)->toContain('var(--shop-ink)');
});

it('keeps the focus ring at 3:1 and the status and primary text at 4.5:1 on Telegram light and dark schemes', function (string $id): void {
    $theme = app(ThemeRegistry::class)->find($id);
    $css = tgThemeCss($id);
    $colors = ['success' => '#007a55', 'warn-deep' => '#bb4d00', 'danger' => '#c10007', 'primary' => strtolower($theme->colors['primary'])];

    foreach (tgSchemes() as $scheme => $ref) {
        foreach ($colors as $name => $fallback) {
            $value = tgEffective($css, $scheme, '--shop-'.$name.'-text', $fallback, strtolower($theme->colors['primary']));

            foreach ($ref['backs'] as $back) {
                expect(Color::contrast($value, $back))->toBeGreaterThanOrEqual(4.5, "{$id} {$scheme}: {$name} {$value} on {$back}");
            }
        }

        $declarations = tgDeclarations($css, tgSchemes()[$scheme]['rule']);
        $focus = isset($declarations['--shop-focus'])
            ? tgResolve($declarations['--shop-focus'], $ref['ink'], strtolower($theme->colors['primary']))
            : tgEffective($css, $scheme, '--shop-focus', strtolower($theme->colors['primary']), strtolower($theme->colors['primary']));

        foreach ($ref['backs'] as $back) {
            expect(Color::contrast($focus, $back))->toBeGreaterThanOrEqual(3.0, "{$id} {$scheme}: focus {$focus} on {$back}");
        }
    }
})->with(fn () => tgThemeIds());

it('keeps the website values of the contrast rules unchanged by the Telegram rules (the same :root hex values)', function (): void {
    $css = tgThemeCss('opalubka');

    expect($css)->toContain(':root{--shop-primary-text: #623216;}')->toContain(':root{--shop-success-text: #094c37;}')
        ->and($css)->toContain(':root{--shop-focus: #151413;}');
});
