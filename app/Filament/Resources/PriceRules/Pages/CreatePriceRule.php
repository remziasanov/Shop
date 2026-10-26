<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceRules\Pages;

use App\Filament\Resources\PriceRules\PriceRuleResource;
use App\Services\Pricing\PriceRuleSaver;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePriceRule extends CreateRecord
{
    protected static string $resource = PriceRuleResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(PriceRuleSaver::class)->save(null, $data);
    }
}
