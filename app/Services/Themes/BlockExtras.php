<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * Reads the block variants and the header / hero extras of a resolved theme for the storefront templates (TH-10,
 * TH-12, TH-14). The theme files ship with the application, but every value that ends up in a `style` attribute is
 * still matched against a strict pattern here, so a malformed entry degrades to "not set" instead of reaching the page.
 */
final class BlockExtras
{
    public const HEADERS = ['H1', 'H2', 'H3', 'H4'];

    public const HEROES = ['G1', 'G2', 'G3', 'G4'];

    /** Header variant H1…H4; anything else (or no theme) is the default H1. */
    public static function header(?ResolvedTheme $theme): string
    {
        $value = $theme->blocks['header'] ?? 'H1';

        return in_array($value, self::HEADERS, true) ? $value : 'H1';
    }

    /** Home hero variant G1…G4; anything else (or no theme) is the default G1. */
    public static function hero(?ResolvedTheme $theme): string
    {
        $value = $theme->blocks['hero'] ?? 'G1';

        return in_array($value, self::HEROES, true) ? $value : 'G1';
    }

    /**
     * CSS custom properties for the home hero (`heroButton`, `heroImage`), as a `style` attribute value ('' when the
     * theme sets none).
     */
    public static function heroStyle(?ResolvedTheme $theme): string
    {
        $style = '';
        $button = $theme->extras['heroButton'] ?? null;

        if (is_array($button) && self::isHex($button['bg'] ?? null) && self::isHex($button['ink'] ?? null)) {
            $style .= '--shop-hero-btn-bg:'.$button['bg'].';--shop-hero-btn-ink:'.$button['ink'].';';
        }

        // Gradients only, through the same tokenizer as every other theme background (no url(), image-set(), escapes).
        $image = CssValue::gradient($theme->extras['heroImage'] ?? null);

        if ($image !== null) {
            $style .= '--shop-hero-image:'.$image.';';
        }

        return $style;
    }

    /** `headerRule`: the bottom border of the header, e.g. `4px double #1F1B16` (null when not set or malformed). */
    public static function headerRule(?ResolvedTheme $theme): ?string
    {
        $rule = $theme->extras['headerRule'] ?? null;

        return is_string($rule) && preg_match('/^[1-9]\d?px (solid|double|dashed|dotted) #[0-9a-fA-F]{6}$/', $rule) === 1 ? $rule : null;
    }

    /** `homeGridColumns`: desktop columns of the home product grid (3…6), null for the setting of the shop. */
    public static function homeGridColumns(?ResolvedTheme $theme): ?int
    {
        $columns = $theme->extras['homeGridColumns'] ?? null;

        return is_int($columns) && $columns >= 3 && $columns <= 6 ? $columns : null;
    }

    private static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }
}
