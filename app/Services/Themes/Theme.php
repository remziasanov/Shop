<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * A theme definition as stored in `resources/themes/*.json` (docs/themes/SPEC.md).
 */
final readonly class Theme
{
    /**
     * @param  array<string, string>  $colors
     * @param  array<string, mixed>  $shape
     * @param  array<string, mixed>  $fonts
     * @param  array<string, string>  $blocks
     * @param  array<string, mixed>  $extras
     */
    public function __construct(
        public string $id,
        public int $n,
        public string $name,
        public ?string $group,
        public ?string $shopType,
        public ?string $niche,
        public ?string $mood,
        public array $colors,
        public array $shape,
        public array $fonts,
        public array $blocks,
        public array $extras,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            n: (int) ($data['n'] ?? 0),
            name: (string) $data['name'],
            group: isset($data['group']) ? (string) $data['group'] : null,
            shopType: isset($data['shopType']) ? (string) $data['shopType'] : null,
            niche: isset($data['niche']) ? (string) $data['niche'] : null,
            mood: isset($data['mood']) ? (string) $data['mood'] : null,
            colors: (array) ($data['colors'] ?? []),
            shape: (array) ($data['shape'] ?? []),
            fonts: (array) ($data['fonts'] ?? []),
            blocks: (array) ($data['blocks'] ?? []),
            extras: (array) ($data['extras'] ?? []),
        );
    }
}
