<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\Pages;

use App\Exceptions\AttributeException;
use App\Filament\Resources\Attributes\AttributeResource;
use App\Models\Attribute;
use App\Services\Catalog\AttributeService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditAttribute extends EditRecord
{
    protected static string $resource = AttributeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AttributeResource::showInFiltersAction(),
            AttributeResource::detachAndDeleteAction(),
            AttributeResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! filled($data['sort_order'] ?? null)) {
            unset($data['sort_order']);
        }

        try {
            return app(AttributeService::class)->updateAttribute($record instanceof Attribute ? $record : Attribute::query()->findOrFail($record->getKey()), $data);
        } catch (AttributeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }
}
