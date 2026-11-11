<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * The characteristic filters of a request in the order of the category's filter set (FL-07). Option filters
 * (`o[...]`, stage 11) will be a second list next to it; nothing here depends on them.
 */
final readonly class AttributeSelection
{
    /**
     * @param  list<AttributeFilter>  $filters  active filters only, in set order
     */
    public function __construct(public array $filters = []) {}

    public function isEmpty(): bool
    {
        return $this->filters === [];
    }

    public function count(): int
    {
        return count($this->filters);
    }

    public function get(string $slug): ?AttributeFilter
    {
        foreach ($this->filters as $filter) {
            if ($filter->slug === $slug) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * The only filter when there is exactly one with exactly one list value (FL-14 «б»).
     */
    public function singleValue(): ?AttributeFilter
    {
        if (count($this->filters) !== 1 || count($this->filters[0]->values) !== 1) {
            return null;
        }

        return $this->filters[0];
    }

    /**
     * Normalized `a` parameter: same selection, same array (attributes in set order, values in dictionary order).
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        if ($this->filters === []) {
            return [];
        }

        $a = [];

        foreach ($this->filters as $filter) {
            $a[$filter->slug] = $filter->toQueryValue();
        }

        return ['a' => $a];
    }

    /**
     * Without one characteristic, or without one value of a list (the chip «×»).
     */
    public function without(string $slug, ?string $valueSlug = null): self
    {
        $kept = [];

        foreach ($this->filters as $filter) {
            if ($filter->slug !== $slug) {
                $kept[] = $filter;

                continue;
            }

            if ($valueSlug === null) {
                continue;
            }

            $values = array_values(array_filter($filter->values, fn (array $value): bool => $value['slug'] !== $valueSlug));

            if ($values !== []) {
                $kept[] = $filter->withValues($values);
            }
        }

        return new self($kept);
    }
}
