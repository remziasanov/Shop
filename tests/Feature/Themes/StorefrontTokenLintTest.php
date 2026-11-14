<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

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

    $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';
    $prefixes = 'bg|text|border|ring|ring-offset|fill|stroke|from|to|via|divide|outline|decoration|placeholder|accent|caret|shadow';
    $rules = [
        'palette color' => "/(?<![\\w-])(?:{$prefixes})-(?:(?:{$palette})-\\d{2,3}|white|black)(?![\\w-])/",
        // A hex color in markup or an inline style; anchors (href="#cart"), HTML entities (&#039;) and PHP regexes are skipped.
        'hex literal' => '/(?<![\w&\/"\'=])#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3})(?![\w-])(?!["\'])/',
        'font-family' => '/font-family\s*:/i',
        'arbitrary color' => '/(?<![\w-])(?:bg|text|border|ring|fill|stroke|from|to|via|divide|outline|decoration|placeholder|accent|caret|shadow)-\[(?:#|rgb|hsl|oklch)/',
    ];

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
    $palette = '/(?<![\w-])(?:bg|text)-(?:(?:slate|red)-\d{2,3}|white|black)(?![\w-])/';

    expect(preg_match($palette, '<p class="hover:bg-slate-50">'))->toBe(1)
        ->and(preg_match($palette, '<p class="text-white/80">'))->toBe(1)
        ->and(preg_match($palette, '<p class="bg-surface-2 text-ink-soft">'))->toBe(0)
        ->and(preg_match($palette, '<p class="text-whitespace">'))->toBe(0);
});

it('defines every contract token in the CSS block (TH-05)', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    foreach (['bg', 'surface', 'ink', 'muted', 'line', 'primary', 'on-primary', 'accent', 'on-accent', 'hero-bg', 'hero-ink', 'tile', 'radius', 'radius-btn', 'border-w', 'font-display', 'font-body', 'font-mono', 'display-weight', 'display-case', 'display-scale'] as $token) {
        expect($css)->toContain('--shop-'.$token.':');
    }
});
