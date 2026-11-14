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
    private const FORMAT = 2;

    private const TTL = 86400;

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

        $css = implode('', $faces).$this->root($theme);

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

        $out = ':root{';

        foreach ($vars as $name => $value) {
            $out .= '--shop-'.$name.': '.$value.';';
        }

        return $out.'}';
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
