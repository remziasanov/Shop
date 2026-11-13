<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\OptionDisplayType;

/**
 * One validated option filter of a request (VR-19): the selected values of a variant option (colour, size…), the
 * counterpart of {@see AttributeFilter} for `o[slug][]=value-slug`.
 */
final readonly class OptionFilter
{
    /**
     * @param  list<array{id: int, slug: string, label: string, sort: int}>  $values  selected values in dictionary order
     */
    public function __construct(
        public int $optionTypeId,
        public string $slug,
        public string $name,
        public OptionDisplayType $display,
        public array $values = [],
    ) {}

    /**
     * @param  list<array{id: int, slug: string, label: string, sort: int}>  $values
     */
    public function withValues(array $values): self
    {
        return new self($this->optionTypeId, $this->slug, $this->name, $this->display, $values);
    }

    public function isActive(): bool
    {
        return $this->values !== [];
    }

    /**
     * @return list<int>
     */
    public function valueIds(): array
    {
        return array_map(fn (array $value): int => $value['id'], $this->values);
    }

    /**
     * The value of `o[slug]` in the normalized query string.
     *
     * @return list<string>
     */
    public function toQueryValue(): array
    {
        return array_map(fn (array $value): string => $value['slug'], $this->values);
    }
}
