<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceRules\Pages;

use App\Filament\Resources\PriceRules\PriceRuleResource;
use App\Filament\Resources\PriceRules\Support\MapsPriceRuleFormData;
use App\Models\PriceRule;
use App\Services\Pricing\PriceRuleSaver;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPriceRule extends EditRecord
{
    use MapsPriceRuleFormData;

    protected static string $resource = PriceRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PriceRuleResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PriceRule $record */
        return app(PriceRuleSaver::class)->save($record, $data);
    }
}
