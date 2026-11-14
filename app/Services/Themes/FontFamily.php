<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * A font family of `resources/themes/fonts.json`. Files live in `public/fonts/<slug>/` (D-135).
 */
final readonly class FontFamily
{
    public const SUBSETS = ['cyrillic', 'latin'];

    /** Standard unicode-range of the two subsets (identical in every fontsource package). */
    public const RANGES = [
        'cyrillic' => 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116',
        'latin' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD',
    ];

    /**
     * @param  list<int>  $weights
     * @param  list<string>  $selectableAs
     */
    public function __construct(
        public string $family,
        public string $slug,
        public string $kind,
        public array $weights,
        public bool $italic,
        public array $selectableAs,
    ) {}

    public function displayOnly(): bool
    {
        return ! in_array('body', $this->selectableAs, true);
    }

    /**
     * Public URL path of one file.
     */
    public function url(string $subset, int $weight, bool $italic = false): string
    {
        return '/fonts/'.$this->slug.'/'.$this->slug.'-'.$subset.'-'.$weight.'-'.($italic ? 'italic' : 'normal').'.woff2';
    }

    public function path(string $subset, int $weight, bool $italic = false): string
    {
        return public_path(ltrim($this->url($subset, $weight, $italic), '/'));
    }

    /**
     * The available weight closest to the wanted one.
     */
    public function nearestWeight(int $wanted): int
    {
        $best = $this->weights[0] ?? 400;

        foreach ($this->weights as $weight) {
            if (abs($weight - $wanted) < abs($best - $wanted)) {
                $best = $weight;
            }
        }

        return $best;
    }
}
