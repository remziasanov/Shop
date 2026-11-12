<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * A value of a list filter with the number of products it would give (FL-09).
 */
final readonly class FacetValue
{
    public function __construct(
        public string $slug,
        public string $label,
        public int $count,
        public bool $selected,
    ) {}

    /**
     * @return array{slug: string, label: string, count: int, selected: bool}
     */
    public function toArray(): array
    {
        return ['slug' => $this->slug, 'label' => $this->label, 'count' => $this->count, 'selected' => $this->selected];
    }

    /**
     * @param  array{slug: string, label: string, count: int, selected: bool}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['slug'], $data['label'], $data['count'], $data['selected']);
    }
}
