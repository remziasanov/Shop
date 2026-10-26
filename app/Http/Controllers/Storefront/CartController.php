<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Exceptions\SalesException;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Cart\CartService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /**
     * SF-42: the page itself is the Livewire component `storefront.cart-page`.
     */
    public function show(): View
    {
        return view('storefront.cart');
    }

    /**
     * Plain form endpoint of the product cards (no Livewire component per card, NF-02): works without JavaScript
     * (redirect back with a flash message) and answers JSON to the Alpine enhancement. The cart rules (sales
     * mode, visibility, stock) are checked in {@see CartService::add()} (SF-41).
     */
    public function add(Request $request, CartService $carts): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:999'],
        ]);

        try {
            $product = Product::query()->active()->find((int) $data['product_id'])
                ?? throw new SalesException(__('storefront_sales.add.not_found'));
            $carts->add($carts->getOrCreate($request), $product, (int) ($data['qty'] ?? 1));
        } catch (SalesException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->with('cart_error', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('storefront_sales.toast.added')]);
        }

        return back()->with('cart_flash', __('storefront_sales.toast.added'));
    }
}
