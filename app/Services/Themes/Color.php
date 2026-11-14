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
}
