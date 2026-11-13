<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Support;

use App\Models\PromoCode;
use App\Services\Promo\PromoCodeSaver;

/**
 * The code pages keep targets and groups in plain form fields: filling reads them through {@see PromoCodeSaver}.
 */
trait MapsPromoCodeFormData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PromoCode $code */
        $code = $this->getRecord();

        return app(PromoCodeSaver::class)->formState($code);
    }
}
