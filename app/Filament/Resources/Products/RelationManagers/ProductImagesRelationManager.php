<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Resources\Products\Support\PhotoUpload;
use App\Models\Product;
use App\Services\Media\ImageProcessor;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Photo management of a product (AD-50…53): upload, drag-and-drop order (the first photo is the main
 * one), editable alt text, deletion that removes the files (via the model observer).
 */
class ProductImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin_products.photos.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alt')
            ->columns([
                ImageColumn::make('path_small')
                    ->label(__('admin_products.photos.thumbnail'))
                    ->disk(ImageProcessor::DISK)
                    ->imageHeight(64),
                TextInputColumn::make('alt')
                    ->label(__('admin_products.photos.alt'))
                    ->rules(['nullable', 'string', 'max:255']),
                TextColumn::make('is_main')
                    ->label('')
                    ->badge()
                    ->state(fn ($rowLoop): string => $rowLoop->first ? __('admin_products.photos.main') : ''),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->paginated(false)
            ->description(__('admin_products.photos.reorder_hint'))
            ->headerActions([
                Action::make('upload')
                    ->label(__('admin_products.photos.upload'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->modalWidth('2xl')
                    ->visible(fn (): bool => $this->remainingSlots() > 0)
                    ->schema(fn (): array => [
                        PhotoUpload::make('files', $this->remainingSlots())
                            ->label(__('admin_products.photos.upload'))
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var Product $product */
                        $product = $this->getOwnerRecord();

                        PhotoUpload::process($product, $data['files'] ?? [], $data['file_names'] ?? null);
                    }),
            ])
            ->recordActions([
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private function remainingSlots(): int
    {
        /** @var Product $product */
        $product = $this->getOwnerRecord();

        return max(0, ImageProcessor::MAX_PER_PRODUCT - $product->images()->count());
    }
}
