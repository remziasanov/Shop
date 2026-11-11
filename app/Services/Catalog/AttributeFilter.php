<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;
use App\Models\Attribute;

/**
 * One validated characteristic filter of a request (FL-07): the selected dictionary values of a list, the range of a
 * number (decimal strings, inclusive, either end may be open) or the «да» flag of a boolean.
 */
final readonly class AttributeFilter
{
    /**
     * @param  list<array{id: int, slug: string, label: string, sort: int}>  $values  selected list values in dictionary order
     */
    public function __construct(
        public int $attributeId,
        public string $slug,
        public string $name,
        public AttributeType $type,
        public ?string $unit = null,
        public bool $indexable = false,
        public array $values = [],
        public ?string $min = null,
        public ?string $max = null,
        public bool $enabled = false,
    ) {}

    public static function for(Attribute $attribute): self
    {
        return new self($attribute->id, $attribute->slug, $attribute->name, $attribute->type, $attribute->unit, $attribute->index_single_value);
    }

    /**
     * @param  list<array{id: int, slug: string, label: string, sort: int}>  $values
     */
    public function withValues(array $values): self
    {
        return new self($this->attributeId, $this->slug, $this->name, $this->type, $this->unit, $this->indexable, $values, null, null, false);
    }

    public function withRange(?string $min, ?string $max): self
    {
        return new self($this->attributeId, $this->slug, $this->name, $this->type, $this->unit, $this->indexable, [], $min, $max, false);
    }

    public function withEnabled(bool $enabled): self
    {
        return new self($this->attributeId, $this->slug, $this->name, $this->type, $this->unit, $this->indexable, [], null, null, $enabled);
    }

    /**
     * Something is selected (a filter without a selection is dropped by the parser).
     */
    public function isActive(): bool
    {
        return match ($this->type) {
            AttributeType::List => $this->values !== [],
            AttributeType::Number => $this->min !== null || $this->max !== null,
            AttributeType::Boolean => $this->enabled,
        };
    }

    /**
     * @return list<int>
     */
    public function valueIds(): array
    {
        return array_map(fn (array $value): int => $value['id'], $this->values);
    }

    public function hasValue(string $slug): bool
    {
        foreach ($this->values as $value) {
            if ($value['slug'] === $slug) {
                return true;
            }
        }

        return false;
    }

    /**
     * The value of `a[slug]` in the normalized query string.
     *
     * @return list<string>|array<string, string>|int
     */
    public function toQueryValue(): array|int
    {
        return match ($this->type) {
            AttributeType::List => array_map(fn (array $value): string => $value['slug'], $this->values),
            AttributeType::Number => array_filter(['min' => $this->min, 'max' => $this->max], fn (?string $bound): bool => $bound !== null),
            AttributeType::Boolean => 1,
        };
    }
}
