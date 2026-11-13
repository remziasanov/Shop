<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages;

use App\Exceptions\AttributeException;
use App\Exceptions\OptionException;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\Concerns\MapsTreeErrors;
use App\Filament\Resources\Categories\Pages\Concerns\SavesCategoryImage;
use App\Models\Category;
use App\Services\Variants\CategoryFilterRows;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;

class EditCategory extends EditRecord
{
    use MapsTreeErrors;
    use SavesCategoryImage;

    protected static string $resource = CategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CategoryResource::openOnSiteAction(),
            CategoryResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $own = (bool) ($data['filters_own'] ?? false);
        $rows = array_values((array) ($data['filters'] ?? []));
        unset($data['filters_own'], $data['filters']);

        return $this->guardTree(fn (): Model => $this->saveWithImage($data, function (array $clean) use ($record, $own, $rows): Model {
            $saved = parent::handleRecordUpdate($record, $clean);

            if ($saved instanceof Category) {
                try {
                    app(CategoryFilterRows::class)->save($saved, $own, $rows);
                } catch (AttributeException|OptionException $e) {
                    // Halts inside the transaction of saveWithImage(): the category is not saved either.
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt;
                }
            }

            return $saved;
        }));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record instanceof Category) {
            $state = app(CategoryFilterRows::class)->state($record);
            $data['filters_own'] = $state['own'];
            $data['filters'] = $state['rows'];
        }

        return $data;
    }
}
