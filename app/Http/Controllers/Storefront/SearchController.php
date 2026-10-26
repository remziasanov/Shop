<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsListing;
use App\Http\Requests\CatalogFilterRequest;
use App\Services\Search\SearchNormalizer;
use Illuminate\Contracts\View\View;

class SearchController extends Controller
{
    use BuildsListing;

    /**
     * SF-22: results with the same sorting and filters as the catalog; not indexed.
     */
    public function __invoke(CatalogFilterRequest $request, SearchNormalizer $normalizer): View
    {
        $term = $request->searchTerm();

        if ($normalizer->words($term) === []) {
            return view('storefront.search.index', ['term' => $term, 'cards' => null]);
        }

        return view('storefront.search.index', $this->listing($request, null, $term, ['q' => $term]) + ['term' => $term]);
    }
}
