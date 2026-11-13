<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\PriceDisplayMode;
use App\Enums\StockDisplay;
use App\Enums\StorefrontSection;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Access\AccessSet;
use App\Services\Access\ViewerContext;
use App\Services\Age\AgeRestrictionResolver;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\ViewerPrice;
use App\Services\Pricing\ViewerPrices;
use App\Services\Sales\AvailabilityService;
use App\Services\Sales\ProductActions;
use App\Services\Stock\Stockable;
use App\Settings\AppearanceSettings;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Support\Facades\Storage;

/**
 * Turns products into display data: prices (SF-31, SF-36), labels, availability (SF-32),
 * action buttons by sales mode (SF-15, 5.6) and images. Costs no queries when `mainImage` is eager loaded.
 */
final class ProductPresenter
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly SalesSettings $sales,
        private readonly AppearanceSettings $appearance,
        private readonly AgeRestrictionResolver $age,
        private readonly ViewerContext $viewer,
        private readonly ViewerPrices $prices,
    ) {}

    /**
     * @param  iterable<Product>  $products
     * @param  array<int, int>  $variantLinks  product id => variant id the card should open (search by variant article, VR-21)
     * @return list<ProductView>
     */
    public function many(iterable $products, bool $absolute = true, array $variantLinks = []): array
    {
        $products = is_array($products) ? $products : iterator_to_array($products, false);
        $group = $this->viewer->group();
        $book = null;
        $ranges = [];

        // GP-09: the prices of the whole page are loaded at once; GP-20: a group that sees no prices costs no price query.
        if ($products !== [] && $this->viewer->access()->showsPrices()) {
            $book = $this->prices->preload($products, $group);
            $cards = [];

            foreach ($products as $product) {
                if ($product->has_variants) {
                    $cards[] = $product->id;
                }
            }

            $ranges = $this->prices->variantRanges($cards, $group);
        }

        $views = [];

        foreach ($products as $product) {
            $views[] = $this->present($product, $absolute, null, $variantLinks[$product->id] ?? null, $book, $ranges);
        }

        return $views;
    }

    /**
     * @param  bool  $absolute  false gives a site-relative URL (for fragments cached across hosts, D-057)
     * @param  ProductVariant|null  $variant  product page: price, availability and buttons of this variant (VR-09); without
     *                                        it a card with variants shows «от X ₽», the aggregate availability and «Выбрать» (VR-08)
     * @param  int|null  $linkVariantId  the card URL gets `?variant=` (VR-10, VR-21)
     * @param  PriceBook|null  $book  the preloaded group prices of the page (GP-09); loaded for this one card when missing
     * @param  array<int, array{min: int, max: int}>|null  $ranges  group price range of cards with variants by card id; read for this card when missing
     */
    public function present(Product $product, bool $absolute = true, ?ProductVariant $variant = null, ?int $linkVariantId = null, ?PriceBook $book = null, ?array $ranges = null): ProductView
    {
        $access = $this->viewer->access();
        // CG-06: a group that sees no prices gets a replacement text; nothing price-related is rendered.
        $priceHidden = ! $access->showsPrices() && ! $product->price_on_request;
        $onRequest = $product->price_on_request || $priceHidden;
        $variant?->setRelation('product', $product);
        $unit = $variant ?? $product;
        $group = $this->viewer->group();
        $listOfVariants = $product->has_variants && $variant === null;
        // GP-20: no price is resolved for a group that sees none (nothing to show, nothing to leak).
        $viewerPrice = null;
        $range = null;

        if (! $onRequest) {
            $book ??= $this->prices->preload([$unit], $group);
            $viewerPrice = $this->prices->for($unit, $group, $book);

            // A group with price data: the range of its variant prices (one query for the page); otherwise the card's own retail range.
            if ($listOfVariants && $book !== null) {
                $range = ($ranges ?? $this->prices->variantRanges([$product->id], $group))[$product->id] ?? null;
            }
        }

        $final = $range['min'] ?? ($viewerPrice !== null ? $viewerPrice->unit : 0);
        $discount = $viewerPrice === null || ! $access->allowsSection(StorefrontSection::Promotions) ? null : $this->discount($final, $viewerPrice->oldPrice);
        $oldPrice = $viewerPrice !== null && $discount !== null && $viewerPrice->oldPrice !== null ? Money::format($viewerPrice->oldPrice) : null;
        $retailPrice = $viewerPrice !== null && $viewerPrice->retailStrike !== null ? Money::format($viewerPrice->retailStrike) : null;
        $tiers = $viewerPrice !== null && ! $listOfVariants ? $this->tierRows($viewerPrice) : [];
        $loginPrice = $priceHidden && $access->priceDisplay === PriceDisplayMode::Login;

        $productActions = $this->availability->actionsFor($unit);
        $image = $product->relationLoaded('mainImage') ? $product->mainImage : null;
        $url = route('product.show', $linkVariantId !== null ? ['slug' => $product->slug, 'variant' => $linkVariantId] : $product->slug, $absolute);

        return new ProductView(
            product: $product,
            url: $url,
            priceOnRequest: $onRequest,
            price: match (true) {
                $loginPrice => __('customer_groups.storefront.price_login'),
                $onRequest => __('storefront.product.price_on_request'),
                $listOfVariants => $this->fromPrice($final, $range['max'] ?? ($product->final_price_max !== null ? Money::fromRubles($product->final_price_max) : $final)),
                default => Money::format($final),
            },
            oldPrice: $oldPrice,
            discount: $discount,
            labels: $this->labels($product, $oldPrice !== null, $access),
            availability: $this->availability($unit, $access),
            mode: $productActions->mode,
            actions: $listOfVariants ? $this->listActions($productActions) : $this->actions($productActions),
            productActions: $productActions,
            image: $image !== null ? $this->image($image, $product) : null,
            placeholderUrl: $this->placeholderUrl(),
            age: $this->age->forProduct($product),
            priceHidden: $priceHidden,
            priceLoginUrl: $loginPrice ? route('customer.login', [], $absolute) : null,
            hasVariants: $product->has_variants,
            variant: $variant,
            retailPrice: $retailPrice,
            tierCaption: $tiers !== [] ? __('group_prices.tier_caption', ['qty' => $tiers[0]['min'], 'price' => $tiers[0]['price']]) : null,
            unitKopecks: $viewerPrice?->unit,
            tiers: $tiers,
        );
    }

    /**
     * The tier rows of a price (GP-10): quantity, unit price in kopecks and as text, saving in percent against quantity 1.
     *
     * @return list<array{min: int, kopecks: int, price: string, saving: int}>
     */
    private function tierRows(ViewerPrice $price): array
    {
        $rows = [];

        foreach ($price->tiers as $tier) {
            $rows[] = ['min' => $tier['min'], 'kopecks' => $tier['price'], 'price' => Money::format($tier['price']), 'saving' => $price->saving($tier['price'])];
        }

        return $rows;
    }

    /**
     * Price text of a card with variants in a listing (VR-08): «от X ₽» when the active variants differ in price.
     */
    private function fromPrice(int $min, int $max): string
    {
        return $max > $min
            ? __('variant_storefront.price_from', ['price' => Money::format($min)])
            : Money::format($min);
    }

    /**
     * Discount in whole percent for an old price over the final price (kopecks), null when there is none.
     */
    private function discount(int $final, ?int $old): ?int
    {
        return $old === null || $old <= 0 || $final >= $old ? null : (int) round(($old - $final) * 100 / $old);
    }

    /**
     * What the product page needs to know about one variant in the picker (VR-09): price, availability and the buttons
     * that are possible for it. Costs no queries when the page's {@see PriceBook} is passed (the variant carries its card).
     *
     * `kop`, `tiers` and `retail` are the viewer group's own price data (GP-10): the unit price at quantity 1 in kopecks,
     * the tiers `[min_qty, kopecks]` and the struck-through retail price; none of them exists for a group without prices.
     *
     * @return array{price: string, old: string|null, discount: int|null, kop: int|null, tiers: list<array{int, int}>, retail: string|null, stock: array{text: string, state: string}|null, can: array{cart: bool, reserve: bool, inquire: bool, out: bool}, cartLabel: string, backorder: bool, max: int|null}
     */
    public function variantState(Product $product, ProductVariant $variant, ?PriceBook $book = null): array
    {
        $access = $this->viewer->access();
        $variant->setRelation('product', $product);
        $actions = $this->availability->actionsFor($variant);
        $onRequest = $product->price_on_request || ! $access->showsPrices();
        $viewerPrice = null;

        if (! $onRequest) {
            $group = $this->viewer->group();
            $viewerPrice = $this->prices->for($variant, $group, $book ?? $this->prices->preload([$variant], $group));
        }

        $discount = $viewerPrice === null || ! $access->allowsSection(StorefrontSection::Promotions) ? null : $this->discount($viewerPrice->unit, $viewerPrice->oldPrice);
        $loginPrice = ! $access->showsPrices() && ! $product->price_on_request && $access->priceDisplay === PriceDisplayMode::Login;

        return [
            'price' => match (true) {
                $loginPrice => __('customer_groups.storefront.price_login'),
                $viewerPrice === null => __('storefront.product.price_on_request'),
                default => Money::format($viewerPrice->unit),
            },
            'old' => $viewerPrice !== null && $discount !== null && $viewerPrice->oldPrice !== null ? Money::format($viewerPrice->oldPrice) : null,
            'discount' => $discount,
            'kop' => $viewerPrice?->unit,
            'tiers' => $viewerPrice !== null ? array_map(fn (array $tier): array => [$tier['min'], $tier['price']], $viewerPrice->tiers) : [],
            'retail' => $viewerPrice !== null && $viewerPrice->retailStrike !== null ? Money::format($viewerPrice->retailStrike) : null,
            'stock' => $this->availability($variant, $access),
            'can' => [
                'cart' => $actions->hasCartButton() && $actions->canAddToCart,
                'reserve' => $actions->hasReservationButton() && $actions->canReserve,
                'inquire' => $actions->canInquire,
                'out' => $actions->outOfStock,
            ],
            'cartLabel' => $actions->backorder ? __('sales.actions.backorder') : __('storefront.actions.cart'),
            'backorder' => $actions->backorder,
            'max' => $actions->maxQty,
        ];
    }

    /**
     * Gallery of a product page: every photo with all variants.
     *
     * @return list<array{thumb: string, src: string, full: string, srcset: string, width: int, height: int, alt: string, option_value_id: int|null}>
     */
    public function gallery(Product $product): array
    {
        $items = [];

        foreach ($product->images as $image) {
            $items[] = [
                'thumb' => $image->urlSmall(),
                'src' => $image->urlMedium(),
                'full' => $image->urlLarge(),
                'srcset' => $image->srcset(),
                'width' => $image->width,
                'height' => $image->height,
                'alt' => $image->alt ?: $product->name,
                'option_value_id' => $image->option_value_id,
            ];
        }

        return $items;
    }

    public function placeholderUrl(): ?string
    {
        $path = $this->appearance->placeholder_image;

        return $path !== null && $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    /**
     * @return list<array{key: string, text: string}>
     */
    private function labels(Product $product, bool $onSale, AccessSet $access): array
    {
        $labels = [];
        $featured = $access->allowsSection(StorefrontSection::Featured);

        if ($featured && $product->is_featured) {
            $labels[] = ['key' => 'hit', 'text' => __('storefront.labels.hit')];
        }

        if ($featured && $product->is_new) {
            $labels[] = ['key' => 'new', 'text' => __('storefront.labels.new')];
        }

        if ($onSale) {
            $labels[] = ['key' => 'sale', 'text' => __('storefront.labels.sale')];
        }

        return $labels;
    }

    /**
     * Availability by the stock display mode; nothing when stock is not tracked in the shop or for the product.
     *
     * @return array{text: string, state: string}|null
     */
    private function availability(Stockable $unit, AccessSet $access): ?array
    {
        // CG-27: the display mode of the group; «inherit» follows the sales settings.
        $display = $access->stockDisplay->resolve($this->sales->stock_display);

        $available = $unit->availableQty();

        if (! $this->sales->stock_tracking || $available === null) {
            return null;
        }

        if ($available <= 0) {
            return ['text' => __('storefront.availability.out'), 'state' => 'out'];
        }

        if ($display === StockDisplay::Exact) {
            return ['text' => __('storefront.availability.exact', ['qty' => $available, 'unit' => $unit->stockProduct()->unit]), 'state' => 'in'];
        }

        if ($display === StockDisplay::Low && $available <= $this->sales->low_stock_threshold) {
            return ['text' => __('storefront.availability.low'), 'state' => 'low'];
        }

        return ['text' => __('storefront.availability.in'), 'state' => 'in'];
    }

    /**
     * Buttons by the effective mode adjusted by stock (5.6). A disabled button is shown but not clickable.
     *
     * @return list<array{key: string, label: string, primary: bool, disabled: bool}>
     */
    private function actions(ProductActions $actions): array
    {
        $buttons = [];

        if ($actions->outOfStock) {
            // Cart / reservation buttons are inactive with the «Нет в наличии» caption (one is enough).
            $buttons[] = ['key' => 'unavailable', 'label' => __('sales.actions.out_of_stock'), 'primary' => true, 'disabled' => true];
        } else {
            if ($actions->hasCartButton() && $actions->canAddToCart) {
                $buttons[] = [
                    'key' => 'cart',
                    'label' => $actions->backorder ? __('sales.actions.backorder') : __('storefront.actions.cart'),
                    'primary' => true,
                    'disabled' => false,
                ];
            }

            if ($actions->hasReservationButton() && $actions->canReserve) {
                $buttons[] = ['key' => 'reservation', 'label' => __('storefront.actions.reservation'), 'primary' => $buttons === [], 'disabled' => false];
            }
        }

        if ($actions->canInquire) {
            $buttons[] = ['key' => 'inquiry', 'label' => __('storefront.actions.inquiry'), 'primary' => $buttons === [], 'disabled' => false];
        }

        return $buttons;
    }

    /**
     * Buttons of a card with variants in a listing (VR-08): nothing is added to the cart or reserved from a list,
     * «Выбрать» opens the card instead. An out of stock card and the inquiry button behave as before.
     *
     * @return list<array{key: string, label: string, primary: bool, disabled: bool}>
     */
    private function listActions(ProductActions $actions): array
    {
        $buttons = [];

        if ($actions->outOfStock) {
            $buttons[] = ['key' => 'unavailable', 'label' => __('sales.actions.out_of_stock'), 'primary' => true, 'disabled' => true];
        } elseif (($actions->hasCartButton() && $actions->canAddToCart) || ($actions->hasReservationButton() && $actions->canReserve)) {
            $buttons[] = ['key' => 'select', 'label' => __('variant_storefront.choose'), 'primary' => true, 'disabled' => false];
        }

        if ($actions->canInquire) {
            $buttons[] = ['key' => 'inquiry', 'label' => __('storefront.actions.inquiry'), 'primary' => $buttons === [], 'disabled' => false];
        }

        return $buttons;
    }

    /**
     * @return array{src: string, srcset: string, width: int, height: int, alt: string}
     */
    private function image(ProductImage $image, Product $product): array
    {
        return [
            'src' => $image->urlMedium(),
            'srcset' => $image->srcset(),
            'width' => $image->width,
            'height' => $image->height,
            'alt' => $image->alt ?: $product->name,
        ];
    }
}
