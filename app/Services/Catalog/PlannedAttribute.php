<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Attribute;

/**
 * One characteristic of the JSON migration plan (FL-04): an existing attribute that the JSON names resolve to, or a new
 * one with the inferred type. Used by the dry run and by the real run.
 */
final class PlannedAttribute
{
    /**
     * @param  list<string>  $names  spellings of the name in the JSON, most frequent first
     * @param  Attribute|null  $existing  set when the dictionary already has it (FL-16 rules 1-2)
     * @param  int|null  $mergedInto  index of the earlier planned attribute that this one collapses into
     * @param  int  $newValues  list values missing from the dictionary
     * @param  int  $products  occurrences in products
     */
    public function __construct(
        public readonly array $names,
        public readonly ?Attribute $existing,
        public readonly ResolvedAttributeName $resolved,
        public readonly AttributeTypeInference $inference,
        public readonly string $name,
        public readonly ?string $unit,
        public readonly int $newValues,
        public readonly int $products,
        public readonly ?int $mergedInto = null,
    ) {}

    public function isNew(): bool
    {
        return $this->existing === null && $this->mergedInto === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'names' => $this->names,
            'name' => $this->name,
            'unit' => $this->unit,
            'type' => $this->inference->type->value,
            'filterable' => $this->inference->filterable,
            'reason' => $this->inference->nonFilterableReason,
            'disputed' => $this->inference->disputed,
            'numeric_share' => round($this->inference->numericShare * 100, 1),
            'distinct' => $this->inference->distinct,
            'new_values' => $this->newValues,
            'products' => $this->products,
            'existing_id' => $this->existing?->id,
        ];
    }
}
