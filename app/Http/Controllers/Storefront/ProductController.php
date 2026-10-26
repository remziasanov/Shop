<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Catalog\Breadcrumbs;
use App\Services\Catalog\ProductPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public const SIMILAR_LIMIT = 8;

    /**
     * SF-30…SF-32, SF-34…SF-36. Inactive or soft-deleted products and products of invisible
     * categories are 404 (SF-16); the redirect handler may still turn that into a 301 (SF-80).
     */
    public function show(string $slug, ProductPresenter $presenter, Breadcrumbs $breadcrumbs): View
    {
        $product = Product::query()
            ->active()
            ->where('slug', $slug)
            ->with('images')
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
        $description = $product->meta_description ?: Str::limit(trim(strip_tags((string) ($product->short_description ?: $product->description))), 160);

        return view('storefront.product.show', [
            'product' => $product,
            'view' => $view,
            'gallery' => $presenter->gallery($product),
            'specs' => $product->specs(),
            'description' => $product->safeDescription(),
            'similar' => $presenter->many($similar),
            'breadcrumbs' => $breadcrumbs->forCategory($product->category_id, $product->name),
            'pageTitle' => $product->meta_title ?: $product->name,
            'metaDescription' => $description,
        ]);
    }
}
