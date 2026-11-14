<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * The 60 font families of TZ appendix Г (TH-18, TH-20), read from `resources/themes/fonts.json`.
 */
final class FontRegistry
{
    /** @var array<string, FontFamily>|null */
    private ?array $families = null;

    public function __construct(private readonly ?string $path = null) {}

    /**
     * @return array<string, FontFamily> keyed by family name
     */
    public function all(): array
    {
        if ($this->families !== null) {
            return $this->families;
        }

        $data = json_decode((string) file_get_contents($this->path ?? resource_path('themes/fonts.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->families = [];

        foreach ((array) ($data['fonts'] ?? []) as $font) {
            $name = (string) $font['family'];
            $this->families[$name] = new FontFamily(
                family: $name,
                slug: self::slug($name),
                kind: (string) $font['kind'],
                weights: array_values(array_map('intval', (array) $font['weights'])),
                italic: (bool) ($font['italic'] ?? false),
                selectableAs: array_values(array_map('strval', (array) $font['selectableAs'])),
            );
        }

        return $this->families;
    }

    public function find(?string $family): ?FontFamily
    {
        return $family === null ? null : ($this->all()[$family] ?? null);
    }

    /**
     * Families that may be chosen as the body font (TH-20).
     *
     * @return array<string, FontFamily>
     */
    public function bodyChoices(): array
    {
        return array_filter($this->all(), fn (FontFamily $font): bool => ! $font->displayOnly());
    }

    public static function slug(string $family): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($family)), '-');
    }
}
