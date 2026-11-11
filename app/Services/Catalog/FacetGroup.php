<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;

/**
 * One filter block of the sidebar: a characteristic with its values (list), range hint (number) or nothing more
 * (boolean). `slug` is the URL key `a[slug]`.
 */
final readonly class FacetGroup
{
    /**
     * @param  list<FacetValue>  $values  list type only: values present in the selection plus the selected ones
     * @param  string|null  $min  number type: smallest value of the selection (decimal text)
     * @param  string|null  $max  number type: largest value of the selection
     * @param  string|null  $selectedMin  number type: the lower bound in the request
     * @param  string|null  $selectedMax  number type: the upper bound in the request
     */
    public function __construct(
        public string $slug,
        public string $name,
        public AttributeType $type,
        public ?string $unit,
        public bool $expanded,
        public array $values = [],
        public ?string $min = null,
        public ?string $max = null,
        public ?string $selectedMin = null,
        public ?string $selectedMax = null,
        public bool $checked = false,
    ) {}

    /**
     * Nothing to choose from: a list without values or a number without a range hint and without a selection.
     */
    public function isEmpty(): bool
    {
        return match ($this->type) {
            AttributeType::List => $this->values === [],
            AttributeType::Number => $this->min === null && $this->selectedMin === null && $this->selectedMax === null,
            AttributeType::Boolean => false,
        };
    }

    public function hasSelection(): bool
    {
        return $this->checked || $this->selectedMin !== null || $this->selectedMax !== null
            || array_filter($this->values, fn (FacetValue $value): bool => $value->selected) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'type' => $this->type->value,
            'unit' => $this->unit,
            'expanded' => $this->expanded,
            'values' => array_map(fn (FacetValue $value): array => $value->toArray(), $this->values),
            'min' => $this->min,
            'max' => $this->max,
            'selectedMin' => $this->selectedMin,
            'selectedMax' => $this->selectedMax,
            'checked' => $this->checked,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array{slug: string, label: string, count: int, selected: bool}> $values */
        $values = $data['values'];

        return new self(
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            type: AttributeType::from((string) $data['type']),
            unit: $data['unit'] !== null ? (string) $data['unit'] : null,
            expanded: (bool) $data['expanded'],
            values: array_map(FacetValue::fromArray(...), $values),
            min: $data['min'] !== null ? (string) $data['min'] : null,
            max: $data['max'] !== null ? (string) $data['max'] : null,
            selectedMin: $data['selectedMin'] !== null ? (string) $data['selectedMin'] : null,
            selectedMax: $data['selectedMax'] !== null ? (string) $data['selectedMax'] : null,
            checked: (bool) $data['checked'],
        );
    }
}
