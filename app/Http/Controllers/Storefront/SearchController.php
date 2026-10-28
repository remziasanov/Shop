<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Enums\StorefrontSection;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\BuildsListing;
use App\Http\Requests\CatalogFilterRequest;
use App\Services\Access\ViewerContext;
use App\Services\Search\SearchNormalizer;
use App\Services\Seo\SeoBuilder;
use Illuminate\Contracts\View\View;

class SearchController extends Controller
{
    use BuildsListing;

    /**
     * SF-22: results with the same sorting and filters as the catalog; not indexed.
     */
    public function __invoke(CatalogFilterRequest $request, SearchNormalizer $normalizer, SeoBuilder $seo, ViewerContext $viewer): View
    {
        $viewer->requireSection(StorefrontSection::Search);
        $viewer->requireSection(StorefrontSection::Catalog);

        $term = $request->searchTerm();
        $meta = $seo->search($term);

        if ($normalizer->words($term) === []) {
            return view('storefront.search.index', ['term' => $term, 'cards' => null, 'seo' => $meta]);
        }

        return view('storefront.search.index', $this->listing($request, null, $term, ['q' => $term]) + ['term' => $term, 'seo' => $meta]);
    }
}
