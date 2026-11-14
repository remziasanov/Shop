<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @return array<string, string> label => regex
 */
function storefrontTokenRules(): array
{
    $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $prefixes = 'bg|text|border|border-[trblxyse]|ring|ring-offset|inset-ring|inset-shadow|fill|stroke|from|to|via|divide|outline|decoration|placeholder|accent|caret|shadow';

    return [
        'palette color' => "/(?<![\\w-])(?:{$prefixes})-(?:(?:{$palette})-\\d{2,3}|white|black)(?![\\w-])/",
        // A hex color in markup, an inline style or a JS string; anchors (href="#cart", id="#x"), HTML entities (&#039;) are skipped.
        'hex literal' => '/(?<![\w&\/=])(?<!href="|href=\'|id="|id=\'|wire:target=")#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3})(?![\w-])/',
        'font-family' => '/font-family\s*:/i',
        'arbitrary color' => "/(?<![\\w-])(?:{$prefixes})-\\[(?:#|rgb|hsl|oklch)/",
        // A color function anywhere inside an arbitrary value, e.g. shadow-[0_1px_0_rgb(...)].
        'color function in arbitrary value' => '/\[[^\]\s]*(?:rgba?|hsla?|oklch)\(/i',
    ];
}

/**
 * TH-06: storefront templates take colors, fonts and radii only from the --shop-* tokens (through the Tailwind
 * utilities mapped in resources/css/app.css), never from the Tailwind palette, hex literals or font-family.
 *
 * @return array<int, string> "path:line  offending text"
 */
function storefrontTokenViolations(): array
{
    $base = resource_path('views');
    $dirs = ['storefront', 'components/storefront', 'components/account', 'livewire/storefront', 'errors'];

    $rules = storefrontTokenRules();

    $violations = [];
    $finder = Finder::create()->files()->name('*.php')->in(array_map(fn (string $d): string => $base.'/'.$d, $dirs));

    foreach ($finder as $file) {
        foreach (preg_split('/\R/', $file->getContents()) ?: [] as $index => $line) {
            foreach ($rules as $label => $pattern) {
                if (preg_match_all($pattern, $line, $m) > 0) {
                    $violations[] = sprintf('%s:%d  %s: %s', substr($file->getPathname(), strlen(base_path()) + 1), $index + 1, $label, implode(' ', $m[0]));
                }
            }
        }
    }

    return $violations;
}

it('has no Tailwind palette colors, hex literals or font-family in storefront templates (TH-06)', function () {
    expect(storefrontTokenViolations())->toBe([]);
});

it('detects offenders (the linter itself works)', function () {
    $rules = storefrontTokenRules();
    $hits = fn (string $rule, string $line): int => (int) preg_match($rules[$rule], $line);

    expect($hits('palette color', '<p class="hover:bg-slate-50">'))->toBe(1)
        ->and($hits('palette color', '<p class="text-white/80">'))->toBe(1)
        ->and($hits('palette color', '<p class="border-t-slate-200">'))->toBe(1)
        ->and($hits('palette color', '<p class="border-b-gray-100 border-x-white">'))->toBe(1)
        ->and($hits('palette color', '<p class="inset-ring-slate-200">'))->toBe(1)
        ->and($hits('palette color', '<p class="inset-shadow-black">'))->toBe(1)
        ->and($hits('palette color', '<p class="bg-surface-2 text-ink-soft border-t border-line">'))->toBe(0)
        ->and($hits('palette color', '<p class="text-whitespace">'))->toBe(0)
        ->and($hits('hex literal', '<p style="color:#fff">'))->toBe(1)
        ->and($hits('hex literal', '<p :style="{ background: \'#ff0000\' }">'))->toBe(1)
        ->and($hits('hex literal', '<a href="#cart" id="x">'))->toBe(0)
        ->and($hits('hex literal', 'x-bind:style="\'color: #abcdef\'"'))->toBe(1)
        ->and($hits('color function in arbitrary value', '<p class="shadow-[0_-1px_0_0_rgb(226_232_240)]">'))->toBe(1)
        ->and($hits('color function in arbitrary value', '<p class="shadow-[0_1px_0_rgba(0,0,0,.1)]">'))->toBe(1)
        ->and($hits('color function in arbitrary value', '<p class="shadow-[0_-1px_0_0_var(--shop-line)]">'))->toBe(0)
        ->and($hits('arbitrary color', '<p class="border-t-[#fff]">'))->toBe(1);
});

it('defines every contract token in the CSS block (TH-05)', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    foreach (['bg', 'surface', 'ink', 'muted', 'line', 'primary', 'on-primary', 'accent', 'on-accent', 'hero-bg', 'hero-ink', 'tile', 'radius', 'radius-btn', 'border-w', 'font-display', 'font-body', 'font-mono', 'display-weight', 'display-case', 'display-scale'] as $token) {
        expect($css)->toContain('--shop-'.$token.':');
    }
});
