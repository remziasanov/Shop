<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Support\MapsProductFormData;
use App\Filament\Resources\Products\Support\PhotoUpload;
use App\Models\Product;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use MapsProductFormData;

    protected static string $resource = ProductResource::class;

    /** @var list<string> */
    protected array $newPhotos = [];

    /** @var array<string, string> */
    protected array $newPhotoNames = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->newPhotos = array_values(array_filter((array) ($data['new_photos'] ?? []), 'is_string'));
        $this->newPhotoNames = (array) ($data['file_names'] ?? []);

        return $this->prepareProductData($data);
    }

    protected function afterCreate(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();

        // D-052: the initial stock goes through StockService (the field itself is not dehydrated).
        $this->applyStockField($product);

        // D-020: uploads are processed after the product exists; a failing file does not block the others (AD-53).
        PhotoUpload::process($product, $this->newPhotos, $this->newPhotoNames);
    }
}
