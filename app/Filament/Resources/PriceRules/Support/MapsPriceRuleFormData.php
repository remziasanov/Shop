<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceRules\Support;

use App\Models\PriceRule;
use App\Services\Pricing\PriceRuleSaver;

/**
 * Pages of the price rule resource keep the targets in plain form fields (category_ids, product_ids, …):
 * filling reads them from the targets, saving goes through {@see PriceRuleSaver}.
 */
trait MapsPriceRuleFormData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PriceRule $rule */
        $rule = $this->getRecord();

        return app(PriceRuleSaver::class)->formState($rule);
    }
}
