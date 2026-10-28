<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogSort;
use App\Enums\StorefrontSection;
use App\Models\Product;
use App\Services\Access\ViewerContext;
use App\Settings\AppearanceSettings;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Storefront product listing (SF-10…SF-14, SF-20, SF-22): base scope, filters, sorting, pagination.
 */
final class CatalogQuery
{
    public const DEFAULT_PER_PAGE = 24;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly AppearanceSettings $appearance,
        private readonly SalesSettings $sales,
        private readonly ViewerContext $viewer,
    ) {}

    public function perPage(): int
    {
        $perPage = $this->appearance->per_page;

        return $perPage >= 1 ? min($perPage, self::MAX_PER_PAGE) : self::DEFAULT_PER_PAGE;
    }

    /**
     * @param  list<int>|null  $categoryIds  limit to these categories (category + descendants); null = whole catalog
     * @param  string|null  $search  search phrase (D-019); null = no search
     * @param  array<string, mixed>  $extraQuery  additional query string parameters kept in pagination links
     */
    public function paginate(CatalogFilters $filters, ?array $categoryIds = null, ?string $search = null, array $extraQuery = []): CatalogResult
    {
        // CG-13: the viewer's group decides which categories, filters and sorts exist at all.
        $filters = $filters->restrictedTo($this->viewer->access());
        $query = $this->filtered($this->base($categoryIds, $search), $filters, true);
        $this->applySort($query, $filters->sort);

        $products = $query
            ->with('mainImage')
            ->paginate($this->perPage())
            ->appends($extraQuery + $filters->toQuery());

        return new CatalogResult($products, $this->brands($filters, $categoryIds, $search));
    }

    /**
     * @param  list<int>|null  $categoryIds
     * @return Builder<Product>
     */
    private function base(?array $categoryIds, ?string $search): Builder
    {
        $query = Product::query()->storefront();

        if ($categoryIds !== null) {
            $query->whereIn('category_id', $categoryIds);
        }

        if ($search !== null) {
            $query->search($search);
        }

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    private function filtered(Builder $query, CatalogFilters $filters, bool $withBrands): Builder
    {
        if ($filters->priceFrom !== null || $filters->priceTo !== null) {
            // Products with a hidden price (SF-36) never match a price range.
            $query->where('price_on_request', false);
        }

        if ($filters->priceFrom !== null) {
            $query->where('final_price', '>=', Money::toRubles($filters->priceFrom));
        }

        if ($filters->priceTo !== null) {
            $query->where('final_price', '<=', Money::toRubles($filters->priceTo));
        }

        if ($filters->inStock && $this->sales->stock_tracking) {
            $query->where(function (Builder $stock): void {
                $stock->whereNull('stock_qty')->orWhereRaw('stock_qty - reserved_qty > 0');
            });
        }

        if ($withBrands && $filters->brands !== []) {
            $query->whereIn('brand', $filters->brands);
        }

        if ($filters->flags !== []) {
            // Several labels are combined with OR: a product matching any selected label is shown.
            $query->where(function (Builder $flags) use ($filters): void {
                foreach ($filters->flags as $flag) {
                    match ($flag) {
                        CatalogFilters::FLAG_HIT => $flags->orWhere('is_featured', true),
                        CatalogFilters::FLAG_NEW => $flags->orWhere('is_new', true),
                        CatalogFilters::FLAG_SALE => $flags->orWhereNotNull('display_old_price'),
                        default => null,
                    };
                }
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySort(Builder $query, CatalogSort $sort): void
    {
        match ($sort) {
            CatalogSort::Default => $query->orderBy('sort_order')->orderByDesc('created_at')->orderByDesc('id'),
            CatalogSort::PriceAsc => $query->orderBy('final_price')->orderBy('id'),
            CatalogSort::PriceDesc => $query->orderByDesc('final_price')->orderBy('id'),
            CatalogSort::Name => $query->orderBy('name')->orderBy('id'),
            CatalogSort::New => $query->orderByDesc('is_new')->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * Distinct brands of the current selection, computed before the brand filter itself is applied
     * so that selecting a brand does not hide the others (SF-13).
     *
     * @param  list<int>|null  $categoryIds
     * @return list<string>
     */
    private function brands(CatalogFilters $filters, ?array $categoryIds, ?string $search): array
    {
        if (! $this->viewer->access()->allowsSection(StorefrontSection::Brands)) {
            return [];
        }

        $brands = $this->filtered($this->base($categoryIds, $search), $filters, false)
            ->reorder()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->map(fn ($brand): string => (string) $brand)
            ->all();

        // Keep selected brands visible even when the other filters leave them without products.
        foreach ($filters->brands as $selected) {
            if (! in_array($selected, $brands, true)) {
                $brands[] = $selected;
            }
        }

        return $brands;
    }
}
