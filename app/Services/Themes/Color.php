<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * Pure color math on #RRGGBB strings (TH-07, TH-08): normalizing, mixing and WCAG 2.x contrast.
 */
final class Color
{
    /**
     * A strict #RRGGBB value (a #RGB shorthand is expanded); null for anything else (TH-09).
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/\A#([0-9a-fA-F]{3})\z/', $value, $m) === 1) {
            return '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        return preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) === 1 ? $value : null;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /**
     * Mixes two colors: `$share` (0..1) of `$a` and the rest of `$b`.
     */
    public static function mix(string $a, string $b, float $share): string
    {
        $share = max(0.0, min(1.0, $share));
        $ca = self::rgb($a);
        $cb = self::rgb($b);
        $out = '#';

        for ($i = 0; $i < 3; $i++) {
            $out .= sprintf('%02X', (int) round($ca[$i] * $share + $cb[$i] * (1 - $share)));
        }

        return $out;
    }

    /**
     * WCAG relative luminance.
     */
    public static function luminance(string $hex): float
    {
        $lin = array_map(static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * Black or white text, whichever reads better on the background.
     */
    public static function readableOn(string $background): string
    {
        return self::contrast('#FFFFFF', $background) >= self::contrast('#000000', $background) ? '#FFFFFF' : '#000000';
    }

    /**
     * The color made lighter in OKLCH, keeping its hue (and as much chroma as the sRGB gamut allows at that lightness),
     * until it reaches `$minRatio` on every background (TD-35: on a dark page a status color mixed toward the cream
     * text loses its hue). Null when even white does not reach it.
     *
     * @param  list<string>  $backs
     */
    public static function lighten(string $color, array $backs, float $minRatio): ?string
    {
        [$lightness, $chroma, $hue] = self::toOklch($color);

        for ($l = $lightness; $l <= 1.0 + 1e-9; $l += 0.01) {
            $candidate = self::fromOklch(min($l, 1.0), $chroma, $hue);
            $ok = true;

            foreach ($backs as $back) {
                if (self::contrast($candidate, $back) < $minRatio) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float, 2: float} lightness 0..1, chroma, hue in radians
     */
    private static function toOklch(string $hex): array
    {
        $lin = array_map(static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        $l = ($lin[0] * 0.4122214708 + $lin[1] * 0.5363325363 + $lin[2] * 0.0514459929) ** (1 / 3);
        $m = ($lin[0] * 0.2119034982 + $lin[1] * 0.6806995451 + $lin[2] * 0.1073969566) ** (1 / 3);
        $s = ($lin[0] * 0.0883024619 + $lin[1] * 0.2817188376 + $lin[2] * 0.6299787005) ** (1 / 3);

        $lightness = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $a = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $b = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;

        return [$lightness, sqrt($a * $a + $b * $b), atan2($b, $a)];
    }

    /**
     * OKLCH to #RRGGBB; a chroma outside the sRGB gamut is reduced until the color fits.
     */
    private static function fromOklch(float $lightness, float $chroma, float $hue): string
    {
        $low = 0.0;
        $high = $chroma;
        $rgb = self::oklchToLinear($lightness, 0.0, $hue);

        if (self::inGamut($rgb) === false) {
            return $lightness >= 0.5 ? '#FFFFFF' : '#000000';
        }

        $fit = self::oklchToLinear($lightness, $high, $hue);

        if (self::inGamut($fit)) {
            $rgb = $fit;
        } else {
            for ($i = 0; $i < 20; $i++) {
                $mid = ($low + $high) / 2;
                $try = self::oklchToLinear($lightness, $mid, $hue);

                if (self::inGamut($try)) {
                    $low = $mid;
                    $rgb = $try;
                } else {
                    $high = $mid;
                }
            }
        }

        $out = '#';

        foreach ($rgb as $c) {
            $c = max(0.0, min(1.0, $c));
            $s = $c <= 0.0031308 ? $c * 12.92 : 1.055 * ($c ** (1 / 2.4)) - 0.055;
            $out .= sprintf('%02X', (int) round($s * 255));
        }

        return $out;
    }

    /**
     * @return array{0: float, 1: float, 2: float} linear sRGB
     */
    private static function oklchToLinear(float $lightness, float $chroma, float $hue): array
    {
        $a = $chroma * cos($hue);
        $b = $chroma * sin($hue);
        $l = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $m = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $s = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        return [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $rgb
     */
    private static function inGamut(array $rgb): bool
    {
        return min($rgb) >= -1e-6 && max($rgb) <= 1 + 1e-6;
    }
}
