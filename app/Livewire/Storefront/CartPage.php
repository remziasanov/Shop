<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Exceptions\SalesException;
use App\Livewire\Storefront\Concerns\RequiresOpenShop;
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

    /** @var array<int|string, int|string> product id => quantity typed by the visitor */
    public array $qtys = [];

    public function updatedQtys(mixed $value, string $key): void
    {
        $this->resetErrorBag();
        $cart = app(CartService::class)->current(request());

        if ($cart === null || $value === '' || $value === null) {
            return;
        }

        try {
            app(CartService::class)->update($cart, (int) $key, QtyLimit::clamp((int) $value));
        } catch (SalesException $e) {
            $this->addError('cart', $e->getMessage());
        }

        $this->dispatch('cart-updated');
    }

    public function remove(int $productId): void
    {
        $this->resetErrorBag();
        $cart = app(CartService::class)->current(request());

        if ($cart !== null) {
            app(CartService::class)->remove($cart, $productId);
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
            $this->qtys[$line->item->product_id] = $line->qty;
        }

        return view('livewire.storefront.cart-page', [
            'summary' => $summary,
            'changedReasons' => session('cart_changed', []),
        ]);
    }
}
