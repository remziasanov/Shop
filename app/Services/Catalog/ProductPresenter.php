<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\StockDisplay;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Sales\AvailabilityService;
use App\Services\Sales\ProductActions;
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
    ) {}

    /**
     * @param  iterable<Product>  $products
     * @return list<ProductView>
     */
    public function many(iterable $products): array
    {
        $views = [];

        foreach ($products as $product) {
            $views[] = $this->present($product);
        }

        return $views;
    }

    public function present(Product $product): ProductView
    {
        $onRequest = $product->price_on_request;
        $discount = $onRequest ? null : $product->discountPercent();
        $oldPrice = (! $onRequest && $product->display_old_price !== null && $discount !== null)
            ? Money::format(Money::fromRubles($product->display_old_price))
            : null;

        $productActions = $this->availability->actionsFor($product);
        $image = $product->relationLoaded('mainImage') ? $product->mainImage : null;

        return new ProductView(
            product: $product,
            url: route('product.show', $product->slug),
            priceOnRequest: $onRequest,
            price: $onRequest ? __('storefront.product.price_on_request') : Money::format(Money::fromRubles($product->final_price)),
            oldPrice: $oldPrice,
            discount: $discount,
            labels: $this->labels($product, $oldPrice !== null),
            availability: $this->availability($product),
            mode: $productActions->mode,
            actions: $this->actions($productActions),
            productActions: $productActions,
            image: $image !== null ? $this->image($image, $product) : null,
            placeholderUrl: $this->placeholderUrl(),
        );
    }

    /**
     * Gallery of a product page: every photo with all variants.
     *
     * @return list<array{thumb: string, src: string, full: string, srcset: string, width: int, height: int, alt: string}>
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
    private function labels(Product $product, bool $onSale): array
    {
        $labels = [];

        if ($product->is_featured) {
            $labels[] = ['key' => 'hit', 'text' => __('storefront.labels.hit')];
        }

        if ($product->is_new) {
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
    private function availability(Product $product): ?array
    {
        $available = $product->availableQty();

        if (! $this->sales->stock_tracking || $available === null) {
            return null;
        }

        if ($available <= 0) {
            return ['text' => __('storefront.availability.out'), 'state' => 'out'];
        }

        if ($this->sales->stock_display === StockDisplay::Exact) {
            return ['text' => __('storefront.availability.exact', ['qty' => $available, 'unit' => $product->unit]), 'state' => 'in'];
        }

        if ($this->sales->stock_display === StockDisplay::Low && $available <= $this->sales->low_stock_threshold) {
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
