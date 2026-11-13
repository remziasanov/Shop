<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Models\User;
use App\Services\Promo\PromoCodeSaver;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreatePromoCode extends CreateRecord
{
    protected static string $resource = PromoCodeResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();

        return app(PromoCodeSaver::class)->save(null, $data, $user instanceof User ? $user : null);
    }
}
