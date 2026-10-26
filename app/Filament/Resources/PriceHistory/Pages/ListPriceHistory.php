<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceHistory\Pages;

use App\Filament\Resources\PriceHistory\PriceHistoryResource;
use Filament\Resources\Pages\ListRecords;

class ListPriceHistory extends ListRecords
{
    protected static string $resource = PriceHistoryResource::class;
}
