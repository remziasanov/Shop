<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\Pages;

use App\Exceptions\AttributeException;
use App\Filament\Resources\Attributes\AttributeResource;
use App\Services\Catalog\AttributeService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class CreateAttribute extends CreateRecord
{
    protected static string $resource = AttributeResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        if (! filled($data['sort_order'] ?? null)) {
            unset($data['sort_order']);
        }

        try {
            return app(AttributeService::class)->createAttribute($data);
        } catch (AttributeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }
}
