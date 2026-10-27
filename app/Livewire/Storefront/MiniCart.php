<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Livewire\Storefront\Concerns\RequiresOpenShop;
use App\Services\Cart\CartService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Cart icon with the item counter (SF-01, SF-03). Rendered twice (header and mobile bottom bar); both instances
 * refresh on the `cart-updated` event.
 */
class MiniCart extends Component
{
    use RequiresOpenShop;

    /** header | bar */
    public string $variant = 'header';

    #[On('cart-updated')]
    public function refresh(): void
    {
        // Re-rendering reads the fresh counter.
    }

    public function render(CartService $carts): View
    {
        return view($this->variant === 'bar' ? 'livewire.storefront.mini-cart-bar' : 'livewire.storefront.mini-cart', ['count' => $carts->countForRequest(request())]);
    }
}
