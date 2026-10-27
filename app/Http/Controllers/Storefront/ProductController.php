<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\Breadcrumbs;
use App\Services\Catalog\ProductPresenter;
use App\Services\Seo\SeoBuilder;
use Illuminate\Contracts\View\View;

class ProductController extends Controller
{
    public const SIMILAR_LIMIT = 8;

    /**
     * SF-30…SF-32, SF-34…SF-36. Inactive or soft-deleted products and products of invisible
     * categories are 404 (SF-16); the redirect handler may still turn that into a 301 (SF-80).
     */
    public function show(string $slug, ProductPresenter $presenter, Breadcrumbs $breadcrumbs, SeoBuilder $seo): View
    {
        $product = Product::query()
            ->active()
            ->where('slug', $slug)
            ->with(['images', 'category'])
            ->firstOrFail();

        $similar = Product::query()
            ->active()
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->with('mainImage')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::SIMILAR_LIMIT)
            ->get();

        $view = $presenter->present($product);
        $trail = $breadcrumbs->forCategory($product->category_id, $product->name);

        return view('storefront.product.show', [
            'product' => $product,
            'view' => $view,
            'gallery' => $presenter->gallery($product),
            'specs' => $product->specs(),
            'description' => $product->safeDescription(),
            'similar' => $presenter->many($similar),
            'breadcrumbs' => $trail,
            'seo' => $seo->product($product, $view, $trail),
        ]);
    }
}
