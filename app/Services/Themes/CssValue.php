<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * Strict validators for the few non-hex CSS values a theme file may carry (TH-09): an rgba() color, a background
 * made of gradients, a `border-bottom`-style rule. Anything that does not match completely is dropped (null), so no
 * free text ever reaches a <style> element.
 */
final class CssValue
{
    private const MAX_LENGTH = 600;

    private const MAX_DEPTH = 4;

    /** Functions allowed inside a gradient background. */
    private const FUNCTIONS = ['linear-gradient', 'radial-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient', 'rgba', 'rgb'];

    /** Keywords allowed inside a gradient background. */
    private const KEYWORDS = ['circle', 'ellipse', 'at', 'to', 'top', 'bottom', 'left', 'right', 'center', 'transparent', 'closest-side', 'closest-corner', 'farthest-side', 'farthest-corner', 'no-repeat', 'repeat', 'repeat-x', 'repeat-y'];

    /**
     * `rgba(r,g,b,a)` with 0..255 channels and 0..1 alpha, spaces allowed after commas only.
     */
    public static function rgba(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/\Argba\((\d{1,3}), ?(\d{1,3}), ?(\d{1,3}), ?(0|1|0?\.\d{1,3}|1\.0{1,3})\)\z/', $value, $m) !== 1) {
            return null;
        }

        if ((int) $m[1] > 255 || (int) $m[2] > 255 || (int) $m[3] > 255) {
            return null;
        }

        return sprintf('rgba(%d,%d,%d,%s)', $m[1], $m[2], $m[3], $m[4]);
    }

    /**
     * A hex color or an rgba() color.
     */
    public static function color(mixed $value): ?string
    {
        return Color::normalize($value) !== null ? strtolower((string) Color::normalize($value)) : self::rgba($value);
    }

    /**
     * A value of the CSS `background` property built from gradients, colors, lengths and `/` only: functions and
     * keywords from fixed lists, balanced parentheses, no url(), no quotes, no semicolons, no braces.
     */
    public static function gradient(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        $tokenPattern = '/\G(?:'
            .'(?<fn>[a-z][a-z-]*)\('
            .'|(?<close>\))'
            .'|(?<hex>#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3}))(?![0-9a-zA-Z])'
            .'|(?<num>-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|%|deg|em|rem)?)(?![0-9a-zA-Z%])'
            .'|(?<word>[a-z][a-z-]*)(?![a-z-])'
            .'|(?<sep>[,\/])'
            .'|(?<space>\s+)'
            .')/A';

        $depth = 0;
        $offset = 0;
        $length = strlen($value);

        while ($offset < $length) {
            if (preg_match($tokenPattern, $value, $m, PREG_UNMATCHED_AS_NULL, $offset) !== 1) {
                return null;
            }

            $offset += strlen($m[0]);

            if ($m['fn'] !== null) {
                if (! in_array($m['fn'], self::FUNCTIONS, true) || ++$depth > self::MAX_DEPTH) {
                    return null;
                }
            } elseif ($m['close'] !== null) {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($m['word'] !== null && ! in_array($m['word'], self::KEYWORDS, true)) {
                return null;
            }
        }

        return $depth === 0 ? preg_replace('/\s+/', ' ', $value) : null;
    }
}
