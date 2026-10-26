<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Support\MapsProductFormData;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Services\Catalog\ProductDuplicator;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Throwable;

class EditProduct extends EditRecord
{
    use MapsProductFormData;

    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProductsTable::openOnSiteAction(),
            Action::make('duplicate')
                ->label(__('admin_products.actions.duplicate'))
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->requiresConfirmation()
                ->visible(fn (Product $record): bool => ! $record->trashed())
                ->action(function (Product $record): void {
                    try {
                        $copy = app(ProductDuplicator::class)->duplicate($record);
                    } catch (Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title(__('admin_products.actions.duplicate_failed'))->send();

                        return;
                    }

                    Notification::make()->success()->title(__('admin_products.actions.duplicated', ['sku' => $copy->sku]))->send();

                    $this->redirect(ProductResource::getUrl('edit', ['record' => $copy]));
                }),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $product */
        $product = $this->getRecord();
        $this->rememberLoadedStock($product);

        return $this->fillSpecs($product, $data);
    }

    protected function afterSave(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();

        $this->applyStockField($product);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->prepareProductData($data);
    }
}
