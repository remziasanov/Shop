<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Pages;

use App\Filament\Resources\PromoLinks\PromoLinkResource;
use App\Filament\Resources\PromoLinks\Support\PromoLinkActions;
use App\Models\PromoLink;
use App\Services\Promo\PromoLinkSaver;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property PromoLink $record
 */
class EditPromoLink extends EditRecord
{
    protected static string $resource = PromoLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            PromoLinkActions::copy(),
            PromoLinkActions::check(),
            PromoLinkActions::preview(),
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PromoLink $link */
        $link = $this->getRecord();

        return app(PromoLinkSaver::class)->formState($link);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PromoLink $record */
        return app(PromoLinkSaver::class)->save($record, $data);
    }
}
