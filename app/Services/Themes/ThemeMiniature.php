<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * Data for the schematic storefront miniature of a gallery card (D-210): the CSS custom properties of the card and the
 *
 * @font-face rules of the two fonts it shows. Everything is taken from a resolved theme, so colors are already
 * #RRGGBB, radii are clamped integers and font names come from the registry; nothing else can reach the CSS.
 */
final class ThemeMiniature
{
    private const CASES = ['none' => 'none', 'upper' => 'uppercase', 'lower' => 'lowercase'];

    private const STACKS = [
        'sans' => 'system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif',
        'serif' => 'Georgia,"Times New Roman",serif',
        'mono' => 'ui-monospace,Menlo,Consolas,monospace',
    ];

    public function __construct(private readonly FontRegistry $fonts) {}

    /**
     * Value of the `style` attribute of the miniature (custom properties only).
     */
    public function style(ResolvedTheme $theme): string
    {
        $c = array_map('strtolower', $theme->colors);
        $display = $theme->fonts['display'];
        $displayFont = $this->fonts->find($display['family'] ?? null);
        $bodyFont = $this->fonts->find($theme->fonts['body']['family'] ?? null);

        $vars = [
            'bg' => $c['bg'], 'surface' => $c['surface'], 'ink' => $c['ink'], 'muted' => $c['muted'], 'line' => $c['line'],
            'tile' => $c['tile'], 'primary' => $c['primary'], 'on-primary' => $c['onPrimary'], 'accent' => $c['accent'],
            'on-accent' => $c['onAccent'], 'hero-bg' => $c['heroBg'], 'hero-ink' => $c['heroInk'],
            // The miniature is about a third of the real size, so the radii are scaled down (at least 1 px for a rounded theme).
            'radius' => $this->scaled($theme->shape['radius']).'px',
            'radius-btn' => $this->scaled($theme->shape['buttonRadius']).'px',
            'bw' => min(2, max(0, (int) $theme->shape['borderWidth'])).'px',
            'font-d' => $this->stack($displayFont),
            'font-b' => $this->stack($bodyFont),
            'weight-d' => (string) (int) ($display['weight'] ?? 700),
            'case-d' => self::CASES[$display['case'] ?? 'none'] ?? 'none',
            'style-d' => ($display['italic'] ?? false) === true ? 'italic' : 'normal',
        ];

        $out = '';

        foreach ($vars as $name => $value) {
            $out .= '--c-'.$name.':'.$value.';';
        }

        return $out;
    }

    /**
     * @return array<string, string> @font-face rules keyed by slug, subset and weight (one face of two cards is one rule)
     */
    public function faces(ResolvedTheme $theme): array
    {
        $faces = [];
        $display = $theme->fonts['display'];
        $displayFont = $this->fonts->find($display['family'] ?? null);
        $bodyFont = $this->fonts->find($theme->fonts['body']['family'] ?? null);

        if ($displayFont !== null) {
            $this->addFaces($faces, $displayFont, $displayFont->nearestWeight((int) ($display['weight'] ?? 700)), ($display['italic'] ?? false) === true && $displayFont->italic);
        }

        if ($bodyFont !== null) {
            $this->addFaces($faces, $bodyFont, $bodyFont->nearestWeight(400), false);
        }

        return $faces;
    }

    /**
     * @param  array<string, string>  $faces
     */
    private function addFaces(array &$faces, FontFamily $font, int $weight, bool $italic): void
    {
        foreach (FontFamily::SUBSETS as $subset) {
            $faces[$font->slug.'-'.$subset.'-'.$weight.($italic ? 'i' : '')] = sprintf(
                '@font-face{font-family:"%s";font-style:%s;font-weight:%d;font-display:swap;src:url(%s) format("woff2");unicode-range:%s}',
                $this->name($font),
                $italic ? 'italic' : 'normal',
                $weight,
                $font->url($subset, $weight, $italic),
                FontFamily::RANGES[$subset],
            );
        }
    }

    private function scaled(mixed $px): int
    {
        $px = max(0, min(999, (int) $px));

        return $px === 0 ? 0 : min(14, max(1, (int) round($px / 3)));
    }

    private function stack(?FontFamily $font): string
    {
        if ($font === null) {
            return self::STACKS['sans'];
        }

        return '"'.$this->name($font).'",'.(self::STACKS[$font->kind] ?? self::STACKS['sans']);
    }

    /** Family name restricted to letters, digits, spaces and hyphens, so it can never close a string or a rule. */
    private function name(FontFamily $font): string
    {
        return (string) preg_replace('/[^A-Za-z0-9 \-]/', '', $font->family);
    }
}
