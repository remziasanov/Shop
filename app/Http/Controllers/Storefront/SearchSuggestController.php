<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogFilterRequest;
use App\Models\Product;
use App\Services\Catalog\ProductPresenter;
use App\Services\Search\SearchNormalizer;
use Illuminate\Http\JsonResponse;

class SearchSuggestController extends Controller
{
    public const LIMIT = 8;

    public const MIN_LENGTH = 2;

    /**
     * SF-21: up to 8 products for the live search dropdown.
     */
    public function __invoke(CatalogFilterRequest $request, SearchNormalizer $normalizer, ProductPresenter $presenter): JsonResponse
    {
        $term = $request->searchTerm();

        if (mb_strlen($normalizer->normalize($term)) < self::MIN_LENGTH) {
            return response()->json(['items' => []]);
        }

        $products = Product::query()
            ->active()
            ->search($term)
            ->with('mainImage')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        $items = [];
        foreach ($presenter->many($products) as $view) {
            $items[] = [
                'name' => $view->product->name,
                'sku' => $view->product->sku,
                'url' => $view->url,
                'price' => $view->price,
                'image' => $view->product->mainImage?->urlSmall(),
                // AG-05: 16 / 18 / null; the dropdown renders it as data-age, CSS does the blur.
                'age' => $view->age?->minAge(),
            ];
        }

        return response()->json(['items' => $items]);
    }
}
