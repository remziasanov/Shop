<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    /**
     * SF-44: an empty cart (or one below the minimum order amount) goes back to the cart page, which explains why.
     */
    public function __invoke(Request $request, CartService $carts, ViewerContext $viewer): View|RedirectResponse
    {
        // CG-18: a staff preview never checks out.
        if ($viewer->isPreview()) {
            return redirect()->route('cart')->with('cart_error', __('customer_groups.storefront.preview_blocked'));
        }

        // CA-01, CG-12: no checkout without a login where the shop or the group requires it.
        if (! $viewer->mayOrder()) {
            return redirect()->guest(route('customer.login'))->with('access_message', __('account.errors.login_required_to_order'));
        }

        $cart = $carts->current($request);

        if ($cart === null || ! $carts->summary($cart)->canCheckout) {
            return redirect()->route('cart');
        }

        return view('storefront.checkout');
    }
}
