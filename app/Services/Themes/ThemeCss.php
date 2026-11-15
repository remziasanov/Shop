<?php

declare(strict_types=1);

namespace App\Services\Themes;

use Illuminate\Support\Facades\Cache;

/**
 * Builds the CSS of a resolved theme: the `:root` tokens (TH-05) and the @font-face rules of the fonts that this theme
 * and its overrides need, nothing else (TH-19). Only validated values reach the output (TH-09). The result is cached
 * per registry version, theme id and overrides hash (D-137).
 */
final class ThemeCss
{
    /** Bump when the generated CSS format changes, so a deploy never serves a stale cached block. */
    private const FORMAT = 6;

    private const TTL = 86400;

    /** WCAG 2.2 non-text contrast of the keyboard focus ring against what is behind it (Hallmark gate 26a). */
    private const FOCUS_MIN_RATIO = 3.0;

    /** Text contrast on the page (WCAG AA, TH-08, Hallmark gate 40). */
    private const TEXT_MIN_RATIO = 4.5;

    /**
     * Status colors written as text right on the page (`text-success`, `text-warn-deep`, `text-danger`): the sRGB values
     * of the fixed oklch tokens of app.css. Their pairs on the status washes are other tokens and stay fixed.
     */
    private const STATUS_TEXT = ['success' => '#007A55', 'warn-deep' => '#BB4D00', 'danger' => '#C10007'];

    /**
     * Reference pages of the Telegram Mini App (D-188): the client replaces bg / surface / ink with its own colors at
     * run time (telegram.js), so the readable text and focus colors cannot be fixed hex values there. They are written
     * as a mix of the theme color toward the live `--shop-ink`, with the share worked out against these typical Telegram
     * backgrounds (page, section, soft panel) and text colors; the dark values apply under `data-tg-scheme="dark"`.
     */
    private const TELEGRAM_SCHEMES = [
        'light' => ['ink' => '#000000', 'backs' => ['#FFFFFF', '#EFEFF3', '#E6E6EA'], 'selector' => 'html[data-tg]'],
        'dark' => ['ink' => '#FFFFFF', 'backs' => ['#212121', '#2C2C2C', '#383838'], 'selector' => 'html[data-tg][data-tg-scheme="dark"]'],
    ];

    /** Tile indexes that get a rotation rule (the rest of a long listing keeps the plain tile color). */
    private const TILE_INDEXES = 96;

    private const STACKS = [
        'sans' => 'system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif',
        'serif' => 'Georgia,"Times New Roman",serif',
        'mono' => 'ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
    ];

    /** Stack of the `system` font (the default theme): no file is loaded. */
    private const SYSTEM_STACK = 'ui-sans-serif,system-ui,sans-serif,"Apple Color Emoji","Segoe UI Emoji","Segoe UI Symbol","Noto Color Emoji"';

    public function __construct(
        private readonly FontRegistry $fonts,
        private readonly ThemeRegistry $themes,
    ) {}

    /**
     * @return array{css: string, preload: list<string>, files: list<string>}
     */
    public function assets(ResolvedTheme $theme): array
    {
        /** @var array{css: string, preload: list<string>, files: list<string>} */
        return Cache::remember(self::cacheKey($theme, $this->themes->version()), self::TTL, fn (): array => $this->build($theme));
    }

    public function forget(ResolvedTheme $theme): void
    {
        Cache::forget(self::cacheKey($theme, $this->themes->version()));
    }

    public static function cacheKey(ResolvedTheme $theme, string $version): string
    {
        return 'theme-css:'.self::FORMAT.':'.$version.':'.$theme->id.':'.self::overridesHash($theme->overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides  sanitized overrides
     */
    public static function overridesHash(array $overrides): string
    {
        ksort($overrides);

        return substr(md5((string) json_encode($overrides)), 0, 10);
    }

    /**
     * @return array{css: string, preload: list<string>, files: list<string>}
     */
    private function build(ResolvedTheme $theme): array
    {
        [$faces, $files, $preload] = $this->fontFaces($theme);

        $css = implode('', $faces).$this->root($theme).$this->focusRules($theme).$this->textRules($theme).$this->telegramRules($theme).$this->tileRules($theme);

        // Defense in depth: nothing that could close the <style> element.
        return ['css' => str_replace(['<', '>'], '', $css), 'preload' => $preload, 'files' => $files];
    }

    /**
     * @return array{0: list<string>, 1: list<string>, 2: list<string>} faces, loaded files, preloaded files
     */
    private function fontFaces(ResolvedTheme $theme): array
    {
        /** @var array<string, array{font: FontFamily, weight: int, italic: bool}> $needed */
        $needed = [];
        $add = function (?FontFamily $font, int $weight, bool $italic = false) use (&$needed): void {
            if ($font !== null) {
                $needed[$font->slug.'-'.$weight.($italic ? 'i' : '')] = ['font' => $font, 'weight' => $weight, 'italic' => $italic];
            }
        };

        $display = $this->fonts->find($theme->fonts['display']['family']);
        $body = $this->fonts->find($theme->fonts['body']['family']);
        $mono = $this->fonts->find($theme->fonts['mono']['family']);

        $add($display, (int) $theme->fonts['display']['weight'], (bool) $theme->fonts['display']['italic']);

        if ($body !== null) {
            foreach ($body->weights as $weight) {
                $add($body, $weight);
            }
        }

        if ($mono !== null) {
            $add($mono, $mono->nearestWeight(400));
        }

        $faces = [];
        $files = [];

        foreach ($needed as $item) {
            foreach (FontFamily::SUBSETS as $subset) {
                $url = $item['font']->url($subset, $item['weight'], $item['italic']);
                $files[] = $url;
                $faces[] = sprintf(
                    '@font-face{font-family:"%s";font-style:%s;font-weight:%d;font-display:swap;src:url(%s) format("woff2");unicode-range:%s}',
                    $item['font']->family,
                    $item['italic'] ? 'italic' : 'normal',
                    $item['weight'],
                    $url,
                    FontFamily::RANGES[$subset],
                );
            }
        }

        $preload = [];

        if ($display !== null) {
            $preload[] = $display->url('cyrillic', (int) $theme->fonts['display']['weight'], (bool) $theme->fonts['display']['italic']);
        }

        if ($body !== null) {
            $preload[] = $body->url('cyrillic', $body->nearestWeight(400));
        }

        return [$faces, $files, array_values(array_unique($preload))];
    }

    private function root(ResolvedTheme $theme): string
    {
        $c = array_map('strtolower', $theme->colors);
        $display = $theme->fonts['display'];

        $vars = [
            'bg' => $c['bg'], 'surface' => $c['surface'], 'ink' => $c['ink'], 'muted' => $c['muted'], 'line' => $c['line'],
            'primary' => $c['primary'], 'on-primary' => $c['onPrimary'], 'accent' => $c['accent'], 'on-accent' => $c['onAccent'],
            'hero-bg' => $c['heroBg'], 'hero-ink' => $c['heroInk'], 'tile' => $c['tile'],
            'radius' => $theme->shape['radius'].'px',
            'radius-btn' => $theme->shape['buttonRadius'].'px',
            'border-w' => $theme->shape['borderWidth'].'px',
            'font-display' => $this->stack($theme->fonts['display']['family'], 'sans'),
            'font-body' => $this->stack($theme->fonts['body']['family'], 'sans'),
            'font-mono' => $this->stack($theme->fonts['mono']['family'], 'mono'),
            'display-weight' => (string) $display['weight'],
            'display-case' => $display['case'],
            'display-scale' => rtrim(rtrim(number_format((float) $display['scale'], 3, '.', ''), '0'), '.'),
            'display-style' => $display['italic'] ? 'italic' : 'normal',
        ];

        // Printed only when the theme differs from the utility weights (700): the heading rule then forces the weight.
        if ((int) $display['weight'] !== 700) {
            $vars['display-weight-set'] = (string) $display['weight'];
        }

        // Optional tokens: printed only when the theme sets them (the CSS falls back to the utility values).
        if ($display['lineHeight'] !== null) {
            $vars['display-line-height'] = rtrim(rtrim(number_format((float) $display['lineHeight'], 3, '.', ''), '0'), '.');
        }

        if ($display['letterSpacing'] !== null) {
            $vars['display-tracking'] = $display['letterSpacing'];
        }

        // Surface tokens (TH-14): printed only when the theme sets them, the surface CSS has fallbacks. Validated again here.
        foreach (['shadowColor' => 'shadow-color', 'pressColor' => 'press-color'] as $key => $name) {
            $color = Color::normalize($theme->shape[$key] ?? null);

            if ($color !== null) {
                $vars[$name] = strtolower($color);
            }
        }

        $tint = CssValue::color($theme->shape['shadowTint'] ?? null);

        if ($tint !== null) {
            $vars['shadow-tint'] = $tint;
        }

        $image = CssValue::gradient($theme->extras['backgroundImage'] ?? null);

        if ($image !== null) {
            $vars['bg-image'] = $image;
        }

        $out = ':root{';

        foreach ($vars as $name => $value) {
            $out .= '--shop-'.$name.': '.$value.';';
        }

        return $out.'}';
    }

    /**
     * Keyboard focus ring (TH-16, Hallmark gate 26a): the ring is the primary color, unless the primary does not reach
     * 3:1 against the page (bg, surface, surface-2) — then the text color — or against the hero band (G2 / G4 on heroBg) — then
     * the hero text color. The controls carry the `focus-visible:outline-primary` utility, so the rule re-points that
     * utility at the token. Printed only for themes that need it: the other themes (default included) keep the CSS as is.
     */
    private function focusRules(ResolvedTheme $theme): string
    {
        $c = $theme->colors;
        // The page: bg, surface and surface-2 (the footer and soft panels: 23 % line over surface, as in app.css).
        $page = true;

        foreach ([$c['bg'], $c['surface'], Color::mix($c['line'], $c['surface'], 0.23)] as $back) {
            $page = $page && Color::contrast($c['primary'], $back) >= self::FOCUS_MIN_RATIO;
        }
        // The hero band exists only in G2 / G4 (heroBg); in G1 / G3 there is nothing to adjust.
        $hasBand = in_array(BlockExtras::hero($theme), ['G2', 'G4'], true);
        $band = ! $hasBand || Color::contrast($c['primary'], $c['heroBg']) >= self::FOCUS_MIN_RATIO;

        if ($page && $band) {
            return '';
        }

        $out = '';

        if (! $page) {
            $out .= ':root{--shop-focus: '.strtolower($c['ink']).';}';
        }

        // The band always gets its own value, so it never inherits the page ring (the text color on a dark band).
        if ($hasBand) {
            $out .= '.hero-band{--shop-focus: '.strtolower($band ? $c['primary'] : $c['heroInk']).';}';
        }

        return $out.'.focus-visible\\:outline-primary:focus-visible{outline-color:var(--shop-focus)}'
            .'.focus-visible\\:ring-primary:focus-visible{--tw-ring-color:var(--shop-focus)}';
    }

    /**
     * Text colors that must read on the page of a theme (Hallmark gate 40, 4.5:1 on bg and surface): the status colors
     * written as text and the primary color written as text (links, the outline buttons, the active tab). Only the text
     * utilities change (fills, borders and the status washes keep the theme / fixed colors).
     * A color that does not reach the ratio is mixed toward the text color of the theme in 5 % steps until it does (the
     * text color itself always does, TH-08). Printed only for the themes that need it; the default theme keeps its
     * approved look, so nothing is printed for it.
     */
    private function textRules(ResolvedTheme $theme): string
    {
        if ($theme->id === ThemeRegistry::DEFAULT_ID) {
            return '';
        }

        $c = $theme->colors;
        // bg, surface and surface-2 (the C3 card and soft panels: 23 % line over surface, as in app.css).
        $backs = [$c['bg'], $c['surface'], Color::mix($c['line'], $c['surface'], 0.23)];
        $out = '';

        foreach ($this->textTargets($theme) as $name => [$color, $base, $hover]) {
            // TD-35: only the status colors are lightened in OKLCH on dark pages; the primary text keeps the approved mix.
            $readable = self::readable($color, $c['ink'], $backs, lightenOnDark: isset(self::STATUS_TEXT[$name]));

            if ($readable !== $color) {
                $out .= ':root{--shop-'.$name.'-text: '.strtolower($readable).';}'.self::textRule($base, $hover, $name);
            }
        }

        return $out;
    }

    /**
     * The text utilities that are re-pointed at a readable color: [color, selectors of the plain state, selectors of
     * the hover state]. Text utilities only, and only on elements without a fill of their own: a status color on its own
     * wash (the discount badge) or as a fill (the «Новинка» label) keeps the fixed token.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function textTargets(ResolvedTheme $theme): array
    {
        $noFill = ':not([class^="bg-"]):not([class*=" bg-"])';
        $targets = [];

        foreach (self::STATUS_TEXT as $name => $color) {
            $targets[$name] = [$color, '.text-'.$name.$noFill, '.hover\\:text-'.$name.':hover'];
        }

        // Links in formatted text (category / page / product descriptions, the «Текст» block) carry `[&_a]:text-primary`.
        $targets['primary'] = [strtoupper($theme->colors['primary']), '.text-primary'.$noFill.',.active\\:text-primary:active,.\\[\\&_a\\]\\:text-primary a', '.hover\\:text-primary:hover'];

        return $targets;
    }

    /**
     * The rule of one text color. The hover part sits in `@media (hover: hover)` like the `hover:` utilities of
     * Tailwind 4, so a tapped link on a phone does not keep the hover color.
     */
    private static function textRule(string $base, string $hover, string $name, string $scope = ''): string
    {
        $prefix = static fn (string $list): string => $scope === '' ? $list : $scope.' '.str_replace(',', ','.$scope.' ', $list);
        $declaration = '{color:var(--shop-'.$name.'-text)}';

        return $prefix($base).$declaration.'@media (hover:hover){'.$prefix($hover).$declaration.'}';
    }

    /**
     * Telegram Mini App (D-188): the focus ring and the text colors above follow the live tokens that telegram.js
     * sets (ink, primary), instead of the fixed hex values worked out against the theme palette — those stay dark
     * while Telegram's dark scheme turns the page dark. Printed for the colors that need it (a web rule exists, or the
     * color does not read on Telegram's light / dark page); the default theme prints nothing, as in the web.
     */
    private function telegramRules(ResolvedTheme $theme): string
    {
        if ($theme->id === ThemeRegistry::DEFAULT_ID) {
            return '';
        }

        $c = $theme->colors;
        $webBacks = [$c['bg'], $c['surface'], Color::mix($c['line'], $c['surface'], 0.23)];
        /** @var array<string, array<string, string>> $vars scheme => [variable => value] */
        $vars = ['light' => [], 'dark' => []];
        $rules = '';

        foreach ($this->textTargets($theme) as $name => [$color, $base, $hover]) {
            $live = $name === 'primary' ? 'var(--shop-primary)' : strtolower($color);
            $shares = [];

            foreach (self::TELEGRAM_SCHEMES as $scheme => $ref) {
                $shares[$scheme] = self::readableShare($color, $ref['ink'], $ref['backs']);
            }

            if (self::readableShare($color, $c['ink'], $webBacks) === 1.0 && min($shares) === 1.0) {
                continue;
            }

            foreach ($shares as $scheme => $share) {
                $vars[$scheme]['--shop-'.$name.'-text'] = self::mixToward($live, $share);
            }

            $rules .= self::textRule($base, $hover, $name, 'html[data-tg]');
        }

        $focus = $this->telegramFocus($theme);

        foreach ($focus['vars'] as $scheme => $value) {
            $vars[$scheme]['--shop-focus'] = $value;
        }

        $out = '';

        foreach (self::TELEGRAM_SCHEMES as $scheme => $ref) {
            if ($vars[$scheme] === [] || ($scheme === 'dark' && $vars['dark'] === $vars['light'])) {
                continue;
            }

            $out .= $ref['selector'].'{'.implode(';', array_map(static fn (string $k, string $v): string => $k.': '.$v, array_keys($vars[$scheme]), $vars[$scheme])).';}';
        }

        return $out.$rules.$focus['rules'];
    }

    /**
     * @return array{vars: array<string, string>, rules: string} the page focus value per scheme and the rules that read it
     */
    private function telegramFocus(ResolvedTheme $theme): array
    {
        $c = $theme->colors;
        $vars = [];

        foreach (self::TELEGRAM_SCHEMES as $scheme => $ref) {
            $fits = true;

            foreach ($ref['backs'] as $back) {
                $fits = $fits && Color::contrast($c['primary'], $back) >= self::FOCUS_MIN_RATIO;
            }

            $vars[$scheme] = $fits ? 'var(--shop-primary)' : 'var(--shop-ink)';
        }

        $webRule = str_contains($this->focusRules($theme), ':root{--shop-focus:');

        if (! $webRule && ! in_array('var(--shop-ink)', $vars, true)) {
            return ['vars' => [], 'rules' => ''];
        }

        $rules = 'html[data-tg] .focus-visible\\:outline-primary:focus-visible{outline-color:var(--shop-focus)}'
            .'html[data-tg] .focus-visible\\:ring-primary:focus-visible{--tw-ring-color:var(--shop-focus)}';

        // The hero band keeps its own ring (it sits on heroBg, not on the page).
        if (in_array(BlockExtras::hero($theme), ['G2', 'G4'], true)) {
            $band = Color::contrast($c['primary'], $c['heroBg']) >= self::FOCUS_MIN_RATIO ? $c['primary'] : $c['heroInk'];
            $rules .= 'html[data-tg] .hero-band{--shop-focus: '.strtolower($band).';}';
        }

        return ['vars' => $vars, 'rules' => $rules];
    }

    /** The color at `$share` (0..1) mixed toward the live text color: the color itself at 1, the text color at 0. */
    private static function mixToward(string $color, float $share): string
    {
        if ($share >= 1.0) {
            return $color;
        }

        if ($share <= 0.0) {
            return 'var(--shop-ink)';
        }

        return 'color-mix(in srgb, '.$color.' '.(int) round($share * 100).'%, var(--shop-ink))';
    }

    /**
     * The color itself when it reaches 4.5:1 on every background, otherwise the color mixed toward the ink (5 % steps).
     *
     * @param  list<string>  $backs
     */
    private static function readable(string $color, string $ink, array $backs, bool $lightenOnDark = false): string
    {
        $share = self::readableShare($color, $ink, $backs);

        // A light text on a dark page: mixing toward it washes the hue out (TD-35), so a status color is lightened in OKLCH instead.
        if ($lightenOnDark && $share < 1.0 && Color::luminance($ink) > Color::luminance($backs[0])) {
            $light = Color::lighten($color, $backs, self::TEXT_MIN_RATIO);

            if ($light !== null) {
                return $light;
            }
        }

        return match (true) {
            $share >= 1.0 => $color,
            $share <= 0.0 => strtoupper($ink),
            default => Color::mix($color, $ink, $share),
        };
    }

    /**
     * Share (1 = the color itself, 0 = the ink) of the color that reaches 4.5:1 on every background, 5 % steps.
     *
     * @param  list<string>  $backs
     */
    private static function readableShare(string $color, string $ink, array $backs): float
    {
        for ($step = 20; $step > 0; $step--) {
            $share = $step / 20;
            $candidate = $step === 20 ? $color : Color::mix($color, $ink, $share);
            $ok = true;

            foreach ($backs as $back) {
                if (Color::contrast($candidate, $back) < self::TEXT_MIN_RATIO) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                return $share;
            }
        }

        return 0.0;
    }

    /**
     * Product photo tiles in rotation (TH-14): `tileColors` / `tilePatterns` become rules on `[data-tile-index]`
     * (set by the card variants), index mod the number of colors. Printed only for themes that have the extras.
     */
    private function tileRules(ResolvedTheme $theme): string
    {
        $colors = [];

        foreach (array_slice(array_values((array) ($theme->extras['tileColors'] ?? [])), 0, 12) as $color) {
            $color = Color::normalize($color);

            if ($color !== null) {
                $colors[] = strtolower($color);
            }
        }

        $patterns = [];

        foreach (array_slice(array_values((array) ($theme->extras['tilePatterns'] ?? [])), 0, 12) as $pattern) {
            $pattern = CssValue::gradient($pattern);

            if ($pattern !== null) {
                $patterns[] = $pattern;
            }
        }

        $steps = max(count($colors), count($patterns));

        if ($steps === 0) {
            return '';
        }

        $out = '';

        for ($k = 0; $k < $steps; $k++) {
            $selectors = [];

            for ($i = $k; $i < self::TILE_INDEXES; $i += $steps) {
                $selectors[] = '[data-tile-index="'.$i.'"]';
            }

            $declarations = '';

            if ($colors !== []) {
                $color = $colors[$k % count($colors)];
                $declarations .= '--shop-tile:'.$color.';background-color:'.$color.';';
            }

            if ($patterns !== []) {
                $declarations .= 'background-image:'.$patterns[$k % count($patterns)].';';
            }

            $out .= implode(',', $selectors).'{'.$declarations.'}';
        }

        return $out;
    }

    private function stack(?string $family, string $fallbackKind): string
    {
        $font = $this->fonts->find($family);

        if ($font === null) {
            return $fallbackKind === 'mono' ? self::STACKS['mono'] : self::SYSTEM_STACK;
        }

        return '"'.$font->family.'",'.(self::STACKS[$font->kind] ?? self::STACKS['sans']);
    }
}
