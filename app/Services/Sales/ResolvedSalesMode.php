<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Enums\SalesMode;
use App\Enums\SalesModeSource;
use App\Models\Category;

/**
 * Effective sales mode of a product and where it comes from (D-016).
 * `sourceCategory` is set only for {@see SalesModeSource::Category}; it is a partial model hydrated
 * from the cached tree (id, name, slug, sales_mode, …), so it costs no query.
 */
final readonly class ResolvedSalesMode
{
    public function __construct(
        public SalesMode $mode,
        public SalesModeSource $source,
        public ?Category $sourceCategory = null,
    ) {}

    /**
     * Human readable label, e.g. «Резерв (из категории “Мебель”)».
     */
    public function label(): string
    {
        return __('catalog.sales_mode_label.'.$this->source->value, [
            'mode' => $this->mode->label(),
            'category' => $this->sourceCategory->name ?? '',
        ]);
    }
}
