<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * The option filters of a request (VR-19) in the order of the category's filter set, plus the «только доступные
 * модификации» switch (`o_available=1`). The characteristic filters live in {@see AttributeSelection}.
 */
final readonly class OptionSelection
{
    public const AVAILABLE_PARAM = 'o_available';

    /**
     * @param  list<OptionFilter>  $filters  active filters only, in set order
     */
    public function __construct(
        public array $filters = [],
        public bool $availableOnly = false,
    ) {}

    public function isEmpty(): bool
    {
        return $this->filters === [] && ! $this->availableOnly;
    }

    /**
     * Number of active filters for the «Фильтры (N)» button: the switch counts as one.
     */
    public function count(): int
    {
        return count($this->filters) + ($this->availableOnly ? 1 : 0);
    }

    public function get(string $slug): ?OptionFilter
    {
        foreach ($this->filters as $filter) {
            if ($filter->slug === $slug) {
                return $filter;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_map(fn (OptionFilter $filter): string => $filter->slug, $this->filters);
    }

    /**
     * Normalized `o` and `o_available` parameters: option filters in set order, values in dictionary order.
     *
     * @return array<string, mixed>
     */
    public function toQuery(): array
    {
        $query = [];

        if ($this->filters !== []) {
            $o = [];

            foreach ($this->filters as $filter) {
                $o[$filter->slug] = $filter->toQueryValue();
            }

            $query['o'] = $o;
        }

        if ($this->availableOnly) {
            $query[self::AVAILABLE_PARAM] = 1;
        }

        return $query;
    }

    /**
     * Without one option, or without one value of it (the chip «×»).
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

        return new self($kept, $this->availableOnly);
    }

    public function withoutAvailable(): self
    {
        return new self($this->filters, false);
    }
}
