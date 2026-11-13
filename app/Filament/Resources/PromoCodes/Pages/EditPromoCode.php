<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Pages;

use App\Filament\Resources\PromoCodes\PromoCodeResource;
use App\Filament\Resources\PromoCodes\Support\MapsPromoCodeFormData;
use App\Models\PromoCode;
use App\Services\Promo\PromoCodeSaver;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * @property PromoCode $record
 */
class EditPromoCode extends EditRecord
{
    use MapsPromoCodeFormData;

    protected static string $resource = PromoCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    /**
     * 22.8: the discount of a code that has been used changes only after the confirmation checkbox.
     */
    protected function beforeSave(): void
    {
        $state = $this->form->getRawState();

        if (
            $this->record->redemptions()->exists()
            && app(PromoCodeSaver::class)->discountChanged($this->record, $state)
            && ! (bool) ($state['confirm_discount_change'] ?? false)
        ) {
            Notification::make()->danger()->title(__('promo_admin.codes.actions.discount_change_blocked'))->send();

            $this->halt();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PromoCode $record */
        return app(PromoCodeSaver::class)->save($record, $data);
    }
}
