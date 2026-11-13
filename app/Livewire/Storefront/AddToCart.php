<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Exceptions\SalesException;
use App\Livewire\Storefront\Concerns\RequiresOpenShop;
use App\Livewire\Storefront\Concerns\SelectsVariant;
use App\Models\Product;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartService;
use App\Services\Catalog\ProductPresenter;
use App\Services\Sales\AvailabilityService;
use App\Settings\SalesSettings;
use App\Support\QtyLimit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * «В корзину» / «В резерв» / «Заказать (под заказ)» of the product page (SF-33, SF-41). Product cards of listings use a plain
 * form instead of this component (NF-02, see CartController::add). The cart is created lazily on the first add.
 */
class AddToCart extends Component
{
    use RequiresOpenShop;
    use SelectsVariant;

    #[Locked]
    public int $productId;

    /** Quantity selector (product page only). */
    public bool $showQty = false;

    public int|string $qty = 1;

    public function add(CartService $carts, AvailabilityService $availability, SalesSettings $settings): void
    {
        $this->resetErrorBag();
        $qty = max(1, (int) $this->qty);
        $product = $this->product();

        if ($qty > QtyLimit::MAX) {
            $this->qty = QtyLimit::MAX;
            $this->addError('cart', __('storefront_sales.add.max_qty', ['max' => QtyLimit::MAX]));

            return;
        }

        if ($product === null) {
            $this->addError('cart', __('storefront_sales.add.not_found'));

            return;
        }

        $unit = $this->unitOf($product);

        if ($unit === null) {
            $this->addError('cart', $this->variantError($product));

            return;
        }

        $actions = $availability->actionsFor($unit);
        $max = $actions->cartMaxQty($actions->cartKind($settings->reservation_via_cart));

        if ($max !== null && $max > 0 && $qty > $max) {
            $this->qty = $max;
            $this->addError('cart', __('storefront_sales.add.only_available', ['max' => $max]));

            return;
        }

        try {
            app(ViewerContext::class)->assertNotPreview();
            $carts->add($carts->getOrCreate(request()), $product, $qty, $this->selectedVariantId());
        } catch (SalesException $e) {
            $this->addError('cart', $e->getMessage());

            return;
        }

        $this->qty = 1;
        $this->dispatch('cart-updated');
        $this->dispatch('cart-toast', message: __('storefront_sales.toast.added'));
    }

    public function render(AvailabilityService $availability, SalesSettings $settings): View
    {
        $product = $this->product();
        $unit = $product !== null ? ($this->unitOf($product) ?? $product) : null;
        $actions = $unit !== null ? $availability->actionsFor($unit) : null;
        // MR-03: «В резерв» for a reservation product, the row kind is decided again by the server on every add.
        $kind = $actions?->cartKind($settings->reservation_via_cart);

        return view('livewire.storefront.add-to-cart', [
            'product' => $product,
            'actions' => $actions,
            'kind' => $kind,
            'cartLabel' => $actions !== null ? ProductPresenter::cartLabel($actions, $kind) : '',
        ]);
    }

    private function product(): ?Product
    {
        return Product::query()->storefront()->find($this->productId);
    }
}
