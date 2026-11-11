<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Report of the dry run of the JSON migration (FL-04, step 1 of the admin action and `--dry-run`).
 */
final class MigrationReport
{
    /**
     * @param  int  $products  products with characteristics in JSON
     * @param  list<PlannedAttribute>  $plan  every planned characteristic (existing, new and merged)
     */
    public function __construct(public readonly int $products, public readonly array $plan) {}

    /**
     * Attributes that will be created.
     *
     * @return list<PlannedAttribute>
     */
    public function toCreate(): array
    {
        return array_values(array_filter($this->plan, fn (PlannedAttribute $p): bool => $p->isNew()));
    }

    /**
     * Attributes of the dictionary that the JSON names resolve to.
     *
     * @return list<PlannedAttribute>
     */
    public function existing(): array
    {
        return array_values(array_filter($this->plan, fn (PlannedAttribute $p): bool => $p->existing !== null));
    }

    /**
     * Dictionary values that will be created (list attributes, new and existing).
     */
    public function valuesToCreate(): int
    {
        return array_sum(array_map(fn (PlannedAttribute $p): int => $p->mergedInto === null ? $p->newValues : 0, $this->plan));
    }

    /**
     * Names that collapse: several spellings of one name, or several names resolving to one characteristic.
     *
     * @return list<list<string>>
     */
    public function mergedNames(): array
    {
        $groups = [];

        foreach ($this->plan as $index => $planned) {
            // Names that resolve to the same existing attribute collapse as well.
            $target = $planned->existing !== null ? 'attribute:'.$planned->existing->id : (string) ($planned->mergedInto ?? $index);
            $groups[$target] = array_merge($groups[$target] ?? [], $planned->names);
        }

        return array_values(array_filter(array_map(fn (array $names): array => array_values(array_unique($names)), $groups), fn (array $names): bool => count($names) > 1));
    }

    /**
     * New characteristics whose type is not clear-cut (some values do not fit it).
     *
     * @return list<PlannedAttribute>
     */
    public function disputed(): array
    {
        return array_values(array_filter($this->toCreate(), fn (PlannedAttribute $p): bool => $p->inference->disputed));
    }

    /**
     * New characteristics created without a filter (FL-04: more than 200 values or values longer than 100 characters).
     *
     * @return list<PlannedAttribute>
     */
    public function nonFilterable(): array
    {
        return array_values(array_filter($this->toCreate(), fn (PlannedAttribute $p): bool => ! $p->inference->filterable));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'products' => $this->products,
            'to_create' => array_map(fn (PlannedAttribute $p): array => $p->toArray(), $this->toCreate()),
            'existing' => array_map(fn (PlannedAttribute $p): array => $p->toArray(), $this->existing()),
            'values_to_create' => $this->valuesToCreate(),
            'merged_names' => $this->mergedNames(),
            'disputed' => array_map(fn (PlannedAttribute $p): array => $p->toArray(), $this->disputed()),
            'non_filterable' => array_map(fn (PlannedAttribute $p): array => $p->toArray(), $this->nonFilterable()),
        ];
    }
}
