<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront;

use App\Enums\StorefrontSection;
use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use App\Services\Access\ViewerContext;
use App\Services\Pricing\PriceListBuilder;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of the wholesale price list (CG-17). `/account/price-list` serves the file of the signed-in customer's group
 * (a hidden section is a 404 for a signed-in customer, CG-04); `/price-list.xlsx` serves the file of «Гость» and exists
 * only while that group sees the section. The file is the one built by {@see PriceListBuilder}; both routes are limited to
 * 10 downloads per hour.
 */
final class PriceListController extends Controller
{
    public function account(ViewerContext $viewer, PriceListBuilder $builder): StreamedResponse
    {
        $viewer->requireSection(StorefrontSection::PriceList);

        return $this->download($viewer->group(), $builder);
    }

    public function guest(ViewerContext $viewer, PriceListBuilder $builder): StreamedResponse
    {
        abort_unless($viewer->guestAccess()->allowsSection(StorefrontSection::PriceList), 404);

        return $this->download(CustomerGroup::guest(), $builder);
    }

    private function download(CustomerGroup $group, PriceListBuilder $builder): StreamedResponse
    {
        abort_unless($builder->isEligible($group) && $builder->exists($group), 404);

        return Storage::disk(PriceListBuilder::DISK)->download($builder->path($group), 'price-list-'.$group->code.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
