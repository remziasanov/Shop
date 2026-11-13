<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Pages;

use App\Filament\Resources\PromoLinks\PromoLinkResource;
use App\Models\User;
use App\Services\Promo\PromoLinkSaver;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreatePromoLink extends CreateRecord
{
    protected static string $resource = PromoLinkResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = Auth::user();

        return app(PromoLinkSaver::class)->save(null, $data, $user instanceof User ? $user : null);
    }
}
