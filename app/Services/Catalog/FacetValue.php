<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * A value of a list filter with the number of products it would give (FL-09). `hex` / `image`: the swatch of a colour option value (VR-19).
 */
final readonly class FacetValue
{
    public function __construct(
        public string $slug,
        public string $label,
        public int $count,
        public bool $selected,
        public ?string $hex = null,
        public ?string $image = null,
    ) {}

    /**
     * @return array{slug: string, label: string, count: int, selected: bool, hex: string|null, image: string|null}
     */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'label' => $this->label, 'count' => $this->count, 'selected' => $this->selected, 'hex' => $this->hex, 'image' => $this->image];
    }

    /**
     * @param  array{slug: string, label: string, count: int, selected: bool, hex?: string|null, image?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['slug'], $data['label'], $data['count'], $data['selected'], $data['hex'] ?? null, $data['image'] ?? null);
    }
}
