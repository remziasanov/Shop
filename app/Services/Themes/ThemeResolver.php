<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * Theme + overrides -> final token values (TH-05, TH-07, TH-09). Pure: no settings, no I/O beyond the registries.
 *
 * Derived colors, when a base color is overridden (D-139):
 *  - muted = 72 % ink + 28 % bg (ink or bg overridden), line = 16 % ink + 84 % bg, tile = 6 % ink + 94 % bg;
 *    the ratios are the medians of the 50 themes of the registry;
 *  - onPrimary / onAccent = black or white, whichever has the better contrast with primary / accent.
 * Without overrides the colors of the theme file are used as they are.
 */
final class ThemeResolver
{
    public const OVERRIDABLE_COLORS = ['bg', 'surface', 'ink', 'primary', 'accent'];

    public const MUTED_SHARE = 0.72;

    public const LINE_SHARE = 0.16;

    public const TILE_SHARE = 0.06;

    private const COLOR_KEYS = ['bg', 'surface', 'ink', 'muted', 'line', 'primary', 'onPrimary', 'accent', 'onAccent', 'heroBg', 'heroInk', 'tile'];

    public function __construct(
        private readonly ThemeRegistry $themes,
        private readonly FontRegistry $fonts,
        private readonly ContrastChecker $contrast,
    ) {}

    /**
     * Keeps only valid overrides: five hex colors, display font from the registry, body font from the registry and
     * not display-only (TH-09, TH-20).
     *
     * @return array{colors?: array<string, string>, fonts?: array<string, string>}
     */
    public function sanitizeOverrides(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach (self::OVERRIDABLE_COLORS as $key) {
            $color = Color::normalize($raw['colors'][$key] ?? null);

            if ($color !== null) {
                $out['colors'][$key] = $color;
            }
        }

        $display = $raw['fonts']['display'] ?? null;

        if (is_string($display) && $this->fonts->find($display) !== null) {
            $out['fonts']['display'] = $display;
        }

        $body = $raw['fonts']['body'] ?? null;

        if (is_string($body) && isset($this->fonts->bodyChoices()[$body])) {
            $out['fonts']['body'] = $body;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function resolve(Theme $theme, array $overrides = []): ResolvedTheme
    {
        $overrides = $this->sanitizeOverrides($overrides);
        $default = $this->themes->default();

        // Colors: the theme value when valid, else the default theme's value.
        $colors = [];

        foreach (self::COLOR_KEYS as $key) {
            $colors[$key] = Color::normalize($theme->colors[$key] ?? null) ?? Color::normalize($default->colors[$key] ?? null) ?? '#000000';
        }

        $set = $overrides['colors'] ?? [];

        foreach ($set as $key => $value) {
            $colors[$key] = $value;
        }

        if (isset($set['ink']) || isset($set['bg'])) {
            $colors['muted'] = Color::mix($colors['ink'], $colors['bg'], self::MUTED_SHARE);
            $colors['line'] = Color::mix($colors['ink'], $colors['bg'], self::LINE_SHARE);
            $colors['tile'] = Color::mix($colors['ink'], $colors['bg'], self::TILE_SHARE);
        }

        if (isset($set['primary'])) {
            $colors['onPrimary'] = Color::readableOn($colors['primary']);
        }

        if (isset($set['accent'])) {
            $colors['onAccent'] = Color::readableOn($colors['accent']);
        }

        return new ResolvedTheme(
            id: $theme->id,
            name: $theme->name,
            colors: $colors,
            shape: $this->shape($theme, $default),
            fonts: $this->fonts($theme, $overrides),
            blocks: $theme->blocks,
            extras: $this->extras($theme->extras),
            overrides: $overrides,
            warnings: $this->contrast->failures($colors),
        );
    }

    /**
     * @return array{radius: int, buttonRadius: int, borderWidth: int, surface: string, shadowColor?: string, shadowTint?: string, pressColor?: string}
     */
    private function shape(Theme $theme, Theme $default): array
    {
        $surfaces = ['none', 'line', 'soft', 'hard', 'press', 'double', 'glass', 'clay'];
        $surface = $theme->shape['surface'] ?? null;

        $shape = [
            'radius' => $this->clampInt($theme->shape['radius'] ?? $default->shape['radius'] ?? 0, 0, 999),
            'buttonRadius' => $this->clampInt($theme->shape['buttonRadius'] ?? $default->shape['buttonRadius'] ?? 0, 0, 999),
            'borderWidth' => $this->clampInt($theme->shape['borderWidth'] ?? $default->shape['borderWidth'] ?? 1, 0, 8),
            'surface' => is_string($surface) && in_array($surface, $surfaces, true) ? $surface : 'line',
        ];

        // Optional surface colors (TH-14): hex only, rgba() only through the strict pattern (TH-09).
        $shadowColor = Color::normalize($theme->shape['shadowColor'] ?? null);
        $pressColor = Color::normalize($theme->shape['pressColor'] ?? null);
        $shadowTint = CssValue::rgba($theme->shape['shadowTint'] ?? null) ?? Color::normalize($theme->shape['shadowTint'] ?? null);

        if ($shadowColor !== null) {
            $shape['shadowColor'] = strtolower($shadowColor);
        }

        if ($shadowTint !== null) {
            $shape['shadowTint'] = strtolower($shadowTint);
        }

        if ($pressColor !== null) {
            $shape['pressColor'] = strtolower($pressColor);
        }

        return $shape;
    }

    /**
     * The extras of TH-14 that the surface layer owns are validated; the others (hero, header, grid) pass through.
     *
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    private function extras(array $extras): array
    {
        $out = $extras;

        foreach (['tileShape', 'tileColors', 'tilePatterns', 'backgroundImage', 'dashedFrames', 'cropMarks'] as $key) {
            unset($out[$key]);
        }

        if (in_array($extras['tileShape'] ?? null, ['arch', 'circle'], true)) {
            $out['tileShape'] = $extras['tileShape'];
        }

        $colors = [];

        foreach (array_slice(array_values((array) ($extras['tileColors'] ?? [])), 0, 12) as $color) {
            $color = Color::normalize($color);

            if ($color !== null) {
                $colors[] = strtolower($color);
            }
        }

        if ($colors !== []) {
            $out['tileColors'] = $colors;
        }

        $patterns = [];

        foreach (array_slice(array_values((array) ($extras['tilePatterns'] ?? [])), 0, 12) as $pattern) {
            $pattern = CssValue::gradient($pattern);

            if ($pattern !== null) {
                $patterns[] = $pattern;
            }
        }

        if ($patterns !== []) {
            $out['tilePatterns'] = $patterns;
        }

        $image = CssValue::gradient($extras['backgroundImage'] ?? null);

        if ($image !== null) {
            $out['backgroundImage'] = $image;
        }

        foreach (['dashedFrames', 'cropMarks'] as $flag) {
            if (($extras[$flag] ?? false) === true) {
                $out[$flag] = true;
            }
        }

        return $out;
    }

    /**
     * @param  array{colors?: array<string, string>, fonts?: array<string, string>}  $overrides
     * @return array{display: array<string, mixed>, body: array<string, mixed>, mono: array<string, mixed>}
     */
    private function fonts(Theme $theme, array $overrides): array
    {
        $display = (array) ($theme->fonts['display'] ?? []);
        $themeDisplay = $this->fonts->find($display['family'] ?? null);
        $displayFamily = isset($overrides['fonts']['display']) ? $this->fonts->find($overrides['fonts']['display']) : $themeDisplay;
        $wanted = $this->clampInt($display['weight'] ?? 700, 100, 900);

        // An overridden family may lack the theme's weight: take the nearest one it has.
        $weight = $displayFamily !== null ? $displayFamily->nearestWeight($wanted) : $wanted;
        $case = match ($display['case'] ?? 'none') {
            'upper', 'uppercase' => 'uppercase',
            'lower', 'lowercase' => 'lowercase',
            default => 'none',
        };
        $scale = is_numeric($display['scale'] ?? null) ? max(0.4, min(2.0, (float) $display['scale'])) : 1.0;
        $lineHeight = is_numeric($display['lineHeight'] ?? null) ? max(0.8, min(2.5, (float) $display['lineHeight'])) : null;
        $spacing = $display['letterSpacing'] ?? null;
        $spacing = is_string($spacing) && preg_match('/\A-?\d{1,2}(?:\.\d{1,3})?em\z/', $spacing) === 1 ? $spacing : null;

        $bodyTheme = $this->fonts->find((array) ($theme->fonts['body'] ?? []) === [] ? null : ($theme->fonts['body']['family'] ?? null));
        $body = isset($overrides['fonts']['body']) ? $this->fonts->find($overrides['fonts']['body']) : $bodyTheme;

        $mono = $this->fonts->find(is_array($theme->fonts['mono'] ?? null) ? ($theme->fonts['mono']['family'] ?? null) : null);

        return [
            'display' => [
                'family' => $displayFamily?->family,
                'weight' => $weight,
                'case' => $case,
                'italic' => (bool) ($display['italic'] ?? false) && $displayFamily !== null && $displayFamily->italic && $displayFamily === $themeDisplay,
                'scale' => $scale,
                'lineHeight' => $lineHeight,
                'letterSpacing' => $spacing,
            ],
            'body' => ['family' => $body?->family],
            'mono' => ['family' => $mono?->family],
        ];
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, is_numeric($value) ? (int) round((float) $value) : $min));
    }
}
