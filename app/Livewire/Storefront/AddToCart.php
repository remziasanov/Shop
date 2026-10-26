<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Exceptions\SalesException;
use App\Models\Product;
use App\Services\Cart\CartService;
use App\Services\Sales\AvailabilityService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * «В корзину» / «Заказать (под заказ)» of the product page (SF-33, SF-41). Product cards of listings use a plain
 * form instead of this component (NF-02, see CartController::add). The cart is created lazily on the first add.
 */
class AddToCart extends Component
{
    public int $productId;

    /** Quantity selector (product page only). */
    public bool $showQty = false;

    public int|string $qty = 1;

    public function add(CartService $carts, AvailabilityService $availability): void
    {
        $this->resetErrorBag();
        $qty = max(1, (int) $this->qty);
        $product = $this->product();

        if ($product === null) {
            $this->addError('cart', __('storefront_sales.add.not_found'));

            return;
        }

        $max = $availability->actionsFor($product)->maxQty;

        if ($max !== null && $max > 0 && $qty > $max) {
            $this->qty = $max;
            $this->addError('cart', __('storefront_sales.add.only_available', ['max' => $max]));

            return;
        }

        try {
            $carts->add($carts->getOrCreate(request()), $product, $qty);
        } catch (SalesException $e) {
            $this->addError('cart', $e->getMessage());

            return;
        }

        $this->qty = 1;
        $this->dispatch('cart-updated');
        $this->dispatch('cart-toast', message: __('storefront_sales.toast.added'));
    }

    public function render(AvailabilityService $availability): View
    {
        $product = $this->product();

        return view('livewire.storefront.add-to-cart', [
            'product' => $product,
            'actions' => $product !== null ? $availability->actionsFor($product) : null,
        ]);
    }

    private function product(): ?Product
    {
        return Product::query()->active()->find($this->productId);
    }
}
