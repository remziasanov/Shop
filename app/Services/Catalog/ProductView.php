<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AgeRestriction;
use App\Enums\SalesMode;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Sales\ProductActions;

/**
 * Display data of one product for the storefront (cards, product page, suggestions).
 */
final readonly class ProductView
{
    /**
     * @param  list<array{key: string, text: string}>  $labels
     * @param  array{text: string, state: string}|null  $availability  state: in|low|out
     * @param  list<array{key: string, label: string, primary: bool, disabled: bool, single?: bool}>  $actions  buttons to render (key: cart|reservation|inquiry|unavailable)
     * @param  array{src: string, srcset: string, width: int, height: int, alt: string}|null  $image
     * @param  bool  $priceHidden  the viewer's group sees no prices (CG-06): `price` holds the replacement text
     * @param  string|null  $priceLoginUrl  sign-in link shown instead of the price (price_display = login)
     * @param  AgeRestriction|null  $age  effective age restriction (AG-05): rendered as data-age, the blur is decided by CSS
     * @param  bool  $hasVariants  the card works with variants (VR-08): listings show «от X ₽» and «Выбрать»
     * @param  ProductVariant|null  $variant  the variant the price, availability and buttons are those of (product page)
     * @param  string|null  $retailPrice  the retail price struck through next to the group price (GP-09, `show_retail_price`)
     * @param  string|null  $tierCaption  «от 10 шт — 900 ₽» under the price of a card in a list (GP-09)
     * @param  int|null  $unitKopecks  the viewer's unit price at quantity 1 (null when none is shown)
     * @param  list<array{min: int, kopecks: int, price: string, saving: int}>  $tiers  the quantity tiers of the viewer's group only (GP-10)
     */
    public function __construct(
        public Product $product,
        public string $url,
        public bool $priceOnRequest,
        public string $price,
        public ?string $oldPrice,
        public ?int $discount,
        public array $labels,
        public ?array $availability,
        public SalesMode $mode,
        public array $actions,
        public ?array $image,
        public ?string $placeholderUrl,
        public ProductActions $productActions,
        public ?AgeRestriction $age = null,
        public bool $priceHidden = false,
        public ?string $priceLoginUrl = null,
        public bool $hasVariants = false,
        public ?ProductVariant $variant = null,
        public ?string $retailPrice = null,
        public ?string $tierCaption = null,
        public ?int $unitKopecks = null,
        public array $tiers = [],
    ) {}
}
