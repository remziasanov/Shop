<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\CategoryAccessMode;
use App\Enums\GroupStockDisplay;
use App\Enums\PriceDisplayMode;
use App\Enums\SalesMode;
use App\Enums\StorefrontSection;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * What one customer group may see and do on the storefront (TZ 25.6): sections, category sets, price display,
 * sales modes, the order minimum, delivery / payment codes, stock display. Computed once per group and catalog
 * version by {@see CatalogAccess} and cached as a plain array ({@see toArray()}).
 *
 * Category sets (ids of the cached category tree):
 *  - `hiddenCategoryIds`: the selected subtree of the «all except» mode;
 *  - `productCategoryIds`: categories whose products are visible (the whole selection of the «only» mode, empty otherwise);
 *  - `navCategoryIds`: categories that may be opened. In «only» mode these also include the ancestors of the
 *    selected categories: such a parent shows only the products of its visible subtree (CG-05).
 */
final class AccessSet
{
    /** @var array<int, true> */
    private array $hiddenSet;

    /** @var array<int, true> */
    private array $productSet;

    /** @var array<int, true> */
    private array $navSet;

    /**
     * @param  list<string>  $sections  StorefrontSection values
     * @param  list<int>  $hiddenCategoryIds
     * @param  list<int>  $productCategoryIds
     * @param  list<int>  $navCategoryIds
     * @param  list<string>|null  $allowedSalesModes  cart / reservation / inquiry; null = all
     * @param  int|null  $minOrderAmount  kopecks; null = the sales setting
     * @param  list<string>|null  $allowedDeliveryCodes
     * @param  list<string>|null  $allowedPaymentCodes
     */
    public function __construct(
        public readonly ?int $groupId,
        public readonly string $groupCode,
        public readonly array $sections,
        public readonly CategoryAccessMode $categoryMode,
        public readonly array $hiddenCategoryIds,
        public readonly array $productCategoryIds,
        public readonly array $navCategoryIds,
        public readonly PriceDisplayMode $priceDisplay,
        public readonly ?array $allowedSalesModes,
        public readonly ?int $minOrderAmount,
        public readonly ?array $allowedDeliveryCodes,
        public readonly ?array $allowedPaymentCodes,
        public readonly GroupStockDisplay $stockDisplay,
        public readonly bool $requiresLoginToOrder = false,
    ) {
        $this->hiddenSet = array_fill_keys($hiddenCategoryIds, true);
        $this->productSet = array_fill_keys($productCategoryIds, true);
        $this->navSet = array_fill_keys($navCategoryIds, true);
    }

    /**
     * Key part of every cache that varies by group.
     */
    public function cacheKey(): string
    {
        return $this->groupId !== null ? (string) $this->groupId : 'code-'.$this->groupCode;
    }

    public function allowsSection(StorefrontSection $section): bool
    {
        return in_array($section->value, $this->sections, true);
    }

    /**
     * Whether the category selection hides anything (otherwise no condition is added to product queries).
     */
    public function restrictsCategories(): bool
    {
        return match ($this->categoryMode) {
            CategoryAccessMode::All => false,
            CategoryAccessMode::AllExcept => $this->hiddenCategoryIds !== [],
            CategoryAccessMode::Only => true,
        };
    }

    /**
     * The category page may be opened (404 otherwise, CG-13).
     */
    public function canSeeCategory(int $categoryId): bool
    {
        return match ($this->categoryMode) {
            CategoryAccessMode::All => true,
            CategoryAccessMode::AllExcept => ! isset($this->hiddenSet[$categoryId]),
            CategoryAccessMode::Only => isset($this->navSet[$categoryId]),
        };
    }

    /**
     * Products of the category are visible (product pages, listings, search, cart).
     */
    public function canSeeProductsIn(int $categoryId): bool
    {
        return match ($this->categoryMode) {
            CategoryAccessMode::All => true,
            CategoryAccessMode::AllExcept => ! isset($this->hiddenSet[$categoryId]),
            CategoryAccessMode::Only => isset($this->productSet[$categoryId]),
        };
    }

    /**
     * The product may be shown and bought: the catalog section is open and its category is accessible.
     */
    public function canSeeProduct(Product $product): bool
    {
        return $this->allowsSection(StorefrontSection::Catalog) && $this->canSeeProductsIn($product->category_id);
    }

    /**
     * Restricts a product query to the categories of the group (one condition on the `category_id` index).
     *
     * @param  Builder<Product>  $query
     */
    public function applyToProducts(Builder $query, string $column = 'products.category_id'): void
    {
        if (! $this->restrictsCategories()) {
            return;
        }

        if ($this->categoryMode === CategoryAccessMode::Only) {
            $query->whereIn($column, $this->productCategoryIds);
        } else {
            $query->whereNotIn($column, $this->hiddenCategoryIds);
        }
    }

    public function showsPrices(): bool
    {
        return ! $this->priceDisplay->hidesPrices();
    }

    /**
     * The group may use the sales mode: «cart» for Cart, «reservation» for Reservation, «inquiry» for Inquiry
     * (Both needs the first two). Showcase is always allowed.
     */
    public function allowsMode(SalesMode $mode): bool
    {
        if ($this->allowedSalesModes === null) {
            return true;
        }

        return match ($mode) {
            SalesMode::Cart => in_array('cart', $this->allowedSalesModes, true),
            SalesMode::Reservation => in_array('reservation', $this->allowedSalesModes, true),
            SalesMode::Both => in_array('cart', $this->allowedSalesModes, true) && in_array('reservation', $this->allowedSalesModes, true),
            SalesMode::Inquiry => in_array('inquiry', $this->allowedSalesModes, true),
            SalesMode::Showcase => true,
        };
    }

    public function allowsDelivery(string $code): bool
    {
        return $this->allowedDeliveryCodes === null || in_array($code, $this->allowedDeliveryCodes, true);
    }

    public function allowsPayment(string $code): bool
    {
        return $this->allowedPaymentCodes === null || in_array($code, $this->allowedPaymentCodes, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'group_id' => $this->groupId,
            'group_code' => $this->groupCode,
            'sections' => $this->sections,
            'category_mode' => $this->categoryMode->value,
            'hidden' => $this->hiddenCategoryIds,
            'products' => $this->productCategoryIds,
            'nav' => $this->navCategoryIds,
            'price_display' => $this->priceDisplay->value,
            'sales_modes' => $this->allowedSalesModes,
            'min_order' => $this->minOrderAmount,
            'delivery' => $this->allowedDeliveryCodes,
            'payment' => $this->allowedPaymentCodes,
            'stock_display' => $this->stockDisplay->value,
            'require_login' => $this->requiresLoginToOrder,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        // A set cached before «order only after login» existed has no such key: it is rebuilt, never guessed.
        if (! array_key_exists('require_login', $data)) {
            throw new \UnexpectedValueException('Outdated access set.');
        }

        return new self(
            groupId: isset($data['group_id']) ? (int) $data['group_id'] : null,
            groupCode: (string) $data['group_code'],
            sections: array_values(array_map('strval', (array) $data['sections'])),
            categoryMode: CategoryAccessMode::from((string) $data['category_mode']),
            hiddenCategoryIds: array_values(array_map('intval', (array) $data['hidden'])),
            productCategoryIds: array_values(array_map('intval', (array) $data['products'])),
            navCategoryIds: array_values(array_map('intval', (array) $data['nav'])),
            priceDisplay: PriceDisplayMode::from((string) $data['price_display']),
            allowedSalesModes: is_array($data['sales_modes']) ? array_values(array_map('strval', $data['sales_modes'])) : null,
            minOrderAmount: isset($data['min_order']) ? (int) $data['min_order'] : null,
            allowedDeliveryCodes: is_array($data['delivery']) ? array_values(array_map('strval', $data['delivery'])) : null,
            allowedPaymentCodes: is_array($data['payment']) ? array_values(array_map('strval', $data['payment'])) : null,
            stockDisplay: GroupStockDisplay::from((string) $data['stock_display']),
            requiresLoginToOrder: (bool) $data['require_login'],
        );
    }
}
