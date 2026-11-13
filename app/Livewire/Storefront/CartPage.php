<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Exceptions\SalesException;
use App\Livewire\Storefront\Concerns\RequiresOpenShop;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartService;
use App\Services\Cart\CartSummary;
use App\Support\QtyLimit;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

/**
 * Cart page (SF-42, SF-43): items at current prices, quantity change (debounced), removal, the price-change
 * notice and the minimum order amount. Prices are never taken from the browser.
 */
class CartPage extends Component
{
    use RequiresOpenShop;

    /** @var array<int|string, int|string> «product id_variant key» => quantity typed by the visitor (VR-12: a variant is a row of its own) */
    public array $qtys = [];

    public function updatedQtys(mixed $value, string $key): void
    {
        $this->resetErrorBag();
        $cart = app(CartService::class)->current(request());

        if ($cart === null || $value === '' || $value === null) {
            return;
        }

        try {
            [$productId, $variantKey] = array_map('intval', explode('_', $key) + [1 => '0']);
            app(CartService::class)->update($cart, $productId, QtyLimit::clamp((int) $value), $variantKey > 0 ? $variantKey : null);
        } catch (SalesException $e) {
            $this->addError('cart', $e->getMessage());
        }

        $this->dispatch('cart-updated');
    }

    public function remove(int $productId, int $variantKey = 0): void
    {
        $this->resetErrorBag();
        $cart = app(CartService::class)->current(request());

        if ($cart !== null) {
            app(CartService::class)->remove($cart, $productId, $variantKey > 0 ? $variantKey : null);
        }

        $this->dispatch('cart-updated');
    }

    /**
     * «Понятно» on the price-change notice (PR-07).
     */
    public function acknowledge(): void
    {
        $cart = app(CartService::class)->current(request());

        if ($cart !== null) {
            app(CartService::class)->acknowledgePrices($cart);
        }
    }

    public function render(CartService $carts): View
    {
        $cart = $carts->current(request());
        $summary = $cart !== null ? $carts->summary($cart) : new CartSummary([], 0, [], 0, 0, false, false);

        $products = [];

        foreach ($summary->lines as $line) {
            if ($line->product !== null) {
                $products[] = $line->product;
            }
        }

        (new Collection($products))->load('mainImage');

        $this->qtys = [];

        foreach ($summary->lines as $line) {
            $this->qtys[$line->item->product_id.'_'.$line->item->variant_key] = $line->qty;
        }

        return view('livewire.storefront.cart-page', [
            'summary' => $summary,
            'changedReasons' => session('cart_changed', []),
            // CA-01, CG-12: a visitor who must sign in first gets a sign-in link instead of the checkout button.
            'mustSignIn' => ! app(ViewerContext::class)->mayOrder(),
        ]);
    }
}
