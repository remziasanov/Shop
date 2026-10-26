<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogSort;
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
    ) {}

    /**
     * Any filter (not sort, not page) is set.
     */
    public function hasFilters(): bool
    {
        return $this->priceFrom !== null
            || $this->priceTo !== null
            || $this->inStock
            || $this->brands !== []
            || $this->flags !== [];
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

        return $query;
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
