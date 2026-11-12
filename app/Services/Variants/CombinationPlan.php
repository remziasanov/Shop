<?php

declare(strict_types=1);

namespace App\Services\Variants;

/**
 * The preview of the combination generator (VR-05): «будет создано N, уже есть M».
 */
final readonly class CombinationPlan
{
    /**
     * @param  list<CombinationRow>  $rows  every selected combination in axis order
     * @param  int  $currentCount  live variants of the card now
     * @param  int  $limit  max_variants_per_product
     */
    public function __construct(
        public array $rows,
        public int $currentCount,
        public int $limit,
    ) {}

    public function toCreateCount(): int
    {
        return count($this->toCreate());
    }

    public function existingCount(): int
    {
        return count($this->rows) - $this->toCreateCount();
    }

    /**
     * @return list<CombinationRow>
     */
    public function toCreate(): array
    {
        return array_values(array_filter($this->rows, fn (CombinationRow $row): bool => ! $row->exists));
    }

    public function exceedsLimit(): bool
    {
        return $this->currentCount + $this->toCreateCount() > $this->limit;
    }

    public function hasSkuConflicts(): bool
    {
        foreach ($this->toCreate() as $row) {
            if ($row->skuTaken) {
                return true;
            }
        }

        return false;
    }

    /**
     * «Будет создано 6, уже есть 2».
     */
    public function summary(): string
    {
        return __('variants.generator.summary', ['create' => $this->toCreateCount(), 'existing' => $this->existingCount()]);
    }

    /**
     * The rows to create as the editable list the generator form submits back to
     * {@see CombinationGenerator::create()}.
     *
     * @return list<array{values: list<int>, sku: string}>
     */
    public function editableList(): array
    {
        return array_map(fn (CombinationRow $row): array => ['values' => $row->valueIds, 'sku' => $row->sku], $this->toCreate());
    }
}
