<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * TH-08: text pairs that fall below the WCAG AA ratio of 4.5:1. A warning never blocks saving.
 */
final class ContrastChecker
{
    public const MIN_RATIO = 4.5;

    /** Pairs: label key => [foreground token, background token]. */
    public const PAIRS = [
        'ink_bg' => ['ink', 'bg'],
        'ink_surface' => ['ink', 'surface'],
        'onPrimary_primary' => ['onPrimary', 'primary'],
        'onAccent_accent' => ['onAccent', 'accent'],
    ];

    /**
     * @param  array<string, string>  $colors  resolved colors
     * @return list<array{pair: string, ratio: float}>
     */
    public function failures(array $colors): array
    {
        $failures = [];

        foreach (self::PAIRS as $pair => [$fg, $bg]) {
            if (! isset($colors[$fg], $colors[$bg])) {
                continue;
            }

            $ratio = Color::contrast($colors[$fg], $colors[$bg]);

            if ($ratio < self::MIN_RATIO) {
                $failures[] = ['pair' => $pair, 'ratio' => round($ratio, 2)];
            }
        }

        return $failures;
    }
}
