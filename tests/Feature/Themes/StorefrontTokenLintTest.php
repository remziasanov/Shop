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

/**
 * The heading size utilities that app.css rescales with --shop-display-scale (unlayered, so a size not listed there
 * would still work, but a responsive variant of an unlisted size would be silently overridden by a listed one).
 *
 * @return array<int, string>
 */
function scaledHeadingSizeClasses(): array
{
    $css = (string) file_get_contents(resource_path('css/app.css'));
    preg_match_all('/:is\(h1, h2, h3\)\.((?:md\\\\:)?text-[a-z0-9]+)\s*\{/', $css, $m);

    return array_map(fn (string $c): string => str_replace('\\:', ':', $c), $m[1]);
}

/**
 * Text size classes (`text-xl`, `md:text-3xl`, ...) written in the opening tag of every h1-h3 of a Blade source. Classes
 * inside Blade expressions count too: ternaries in `{{ ... }}` (with single or double quotes inside), `@class([...])`
 * and Alpine `:class` bindings; the tag ends at the first `>` outside such an expression.
 *
 * @return list<string>
 */
function headingSizeClasses(string $source): array
{
    $tag = '/<h[1-3]\b((?:\{\{.*?\}\}|\{!!.*?!!\}|@class\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)|[^>])*)>/s';
    $size = '/(?<![\w-])((?:[a-z0-9-]+:)*text-(?:xs|sm|base|lg|xl|[2-9]xl))(?![\w-])/';
    $found = [];

    if (preg_match_all($tag, $source, $tags) > 0) {
        foreach ($tags[1] as $attributes) {
            if (preg_match_all($size, $attributes, $m) > 0) {
                array_push($found, ...$m[1]);
            }
        }
    }

    return $found;
}

it('uses only text sizes covered by the display-scale rules on storefront headings (TH-05)', function () {
    $covered = scaledHeadingSizeClasses();
    // Sizes below text-lg are not scaled on purpose (small item-level headings).
    $unscaled = ['text-xs', 'text-sm', 'text-base'];

    expect($covered)->toContain('text-lg', 'text-xl', 'text-2xl', 'text-3xl', 'md:text-2xl', 'md:text-3xl', 'md:text-4xl');

    $base = resource_path('views');
    $dirs = ['storefront', 'components/storefront', 'components/account', 'livewire/storefront', 'errors'];
    $offenders = [];

    foreach (Finder::create()->files()->name('*.php')->in(array_map(fn (string $d): string => $base.'/'.$d, $dirs)) as $file) {
        foreach (headingSizeClasses($file->getContents()) as $class) {
            if (! in_array($class, $covered, true) && ! in_array($class, $unscaled, true)) {
                $offenders[] = substr($file->getPathname(), strlen(base_path()) + 1).': '.$class;
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('reads the heading sizes inside Blade ternaries, @class and :class (the linter itself works)', function () {
    expect(headingSizeClasses('<h2 class="{{ $big ? \'text-5xl\' : \'text-sm\' }}">x</h2>'))->toBe(['text-5xl', 'text-sm'])
        ->and(headingSizeClasses('<h3 class="mt-2 {{ $n > 1 ? "md:text-4xl" : \'text-xl\' }} font-body">'))->toBe(['md:text-4xl', 'text-xl'])
        ->and(headingSizeClasses('<h1 @class([\'text-7xl\' => $a, \'text-lg\'])>'))->toBe(['text-7xl', 'text-lg'])
        ->and(headingSizeClasses('<h3 :class="open ? \'text-2xl\' : \'text-base\'" class="font-body">'))->toBe(['text-2xl', 'text-base'])
        ->and(headingSizeClasses('<h3 class="font-body text-sm">'))->toBe(['text-sm'])
        ->and(headingSizeClasses('<p class="text-7xl"><h2 class="font-bold">'))->toBe([])
        ->and(headingSizeClasses('<h4 class="text-9xl">'))->toBe([]);
});
