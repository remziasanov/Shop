<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogSort;
use App\Enums\StorefrontSection;
use App\Services\Access\AccessSet;
use App\Support\Money;

/**
 * Validated sort/filter input of a catalog or search page (SF-12, SF-13). Prices are kopecks.
 */
final readonly class CatalogFilters
{
    public const FLAG_HIT = 'hit';

    public const FLAG_NEW = 'new';

    public const FLAG_SALE = 'sale';

    public const FLAGS = [self::FLAG_HIT, self::FLAG_NEW, self::FLAG_SALE];

    /**
     * @param  list<string>  $brands
     * @param  list<string>  $flags
     */
    public function __construct(
        public CatalogSort $sort = CatalogSort::Default,
        public ?int $priceFrom = null,
        public ?int $priceTo = null,
        public bool $inStock = false,
        public array $brands = [],
        public array $flags = [],
        public int $page = 1,
        public AttributeSelection $attributes = new AttributeSelection,
        public OptionSelection $options = new OptionSelection,
    ) {}

    /**
     * The same filters with the characteristic selection of a category (FL-07, FL-15: categories only).
     */
    public function withAttributes(AttributeSelection $attributes): self
    {
        return new self($this->sort, $this->priceFrom, $this->priceTo, $this->inStock, $this->brands, $this->flags, $this->page, $attributes, $this->options);
    }

    /**
     * The same filters with the option selection of a category (VR-19, categories only like the characteristics).
     */
    public function withOptions(OptionSelection $options): self
    {
        return new self($this->sort, $this->priceFrom, $this->priceTo, $this->inStock, $this->brands, $this->flags, $this->page, $this->attributes, $options);
    }

    /**
     * The filters the group may use (CG-04, CG-06): hidden prices remove the price range and the price sorting, hidden
     * sections remove the brand filter and the «хит» / «новинка» / «скидка» labels. Hidden values never reach a query.
     */
    public function restrictedTo(AccessSet $access): self
    {
        $flags = array_values(array_filter($this->flags, fn (string $flag): bool => match ($flag) {
            self::FLAG_HIT, self::FLAG_NEW => $access->allowsSection(StorefrontSection::Featured),
            self::FLAG_SALE => $access->allowsSection(StorefrontSection::Promotions) && $access->showsPrices(),
            default => false,
        }));
        $prices = $access->showsPrices();

        return new self(
            sort: ! $prices && in_array($this->sort, [CatalogSort::PriceAsc, CatalogSort::PriceDesc], true) ? CatalogSort::Default : $this->sort,
            priceFrom: $prices ? $this->priceFrom : null,
            priceTo: $prices ? $this->priceTo : null,
            inStock: $this->inStock,
            brands: $access->allowsSection(StorefrontSection::Brands) ? $this->brands : [],
            flags: $flags,
            page: $this->page,
            attributes: $this->attributes,
            options: $this->options,
        );
    }

    /**
     * Any filter (not sort, not page) is set.
     */
    public function hasFilters(): bool
    {
        return $this->hasBuiltInFilters() || ! $this->attributes->isEmpty() || ! $this->options->isEmpty();
    }

    /**
     * A built-in filter of SF-13 (price, stock, brand, labels) is set.
     */
    public function hasBuiltInFilters(): bool
    {
        return $this->priceFrom !== null
            || $this->priceTo !== null
            || $this->inStock
            || $this->brands !== []
            || $this->flags !== [];
    }

    /**
     * Number of active filters for the «Фильтры (N)» button: a price range counts once, so do brands and labels.
     */
    public function activeCount(): int
    {
        return ($this->priceFrom !== null || $this->priceTo !== null ? 1 : 0)
            + ($this->inStock ? 1 : 0)
            + ($this->brands !== [] ? 1 : 0)
            + ($this->flags !== [] ? 1 : 0)
            + $this->attributes->count()
            + $this->options->count();
    }

    /**
     * Prices for form inputs, in rubles ("1290" or "1290.50").
     */
    public function priceFromInput(): string
    {
        return self::rubles($this->priceFrom);
    }

    public function priceToInput(): string
    {
        return self::rubles($this->priceTo);
    }

    /**
     * Normalized query string parameters (only valid values), used for pagination links and canonical URLs.
     *
     * @return array<string, mixed>
     */
    public function toQuery(bool $withSort = true): array
    {
        $query = [];

        if ($withSort && $this->sort !== CatalogSort::Default) {
            $query['sort'] = $this->sort->value;
        }

        if ($this->priceFrom !== null) {
            $query['price_from'] = $this->priceFromInput();
        }

        if ($this->priceTo !== null) {
            $query['price_to'] = $this->priceToInput();
        }

        if ($this->inStock) {
            $query['in_stock'] = 1;
        }

        if ($this->brands !== []) {
            $query['brands'] = $this->brands;
        }

        if ($this->flags !== []) {
            $query['flags'] = $this->flags;
        }

        return $query + $this->attributes->toQuery() + $this->options->toQuery();
    }

    private static function rubles(?int $kopecks): string
    {
        if ($kopecks === null) {
            return '';
        }

        $text = Money::toRubles($kopecks);

        return str_ends_with($text, '.00') ? substr($text, 0, -3) : $text;
    }
}
