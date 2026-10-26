<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * A raw file row after the column mapping.
 */
final readonly class MappedRow
{
    /**
     * @param  array<string, string|null>  $values  field key => raw cell text (null = blank cell). Only mapped
     *                                              fields are present, so "column absent" and "cell blank" differ.
     * @param  list<array{name: string, value: string}>  $attributes  characteristics with a non-blank value
     */
    public function __construct(
        public array $values,
        public array $attributes = [],
    ) {}

    public function get(string $field): ?string
    {
        return $this->values[$field] ?? null;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->values);
    }
}
