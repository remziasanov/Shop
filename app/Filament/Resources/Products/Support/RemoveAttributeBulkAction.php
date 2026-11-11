<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Exceptions\AttributeException;
use App\Models\Attribute;
use App\Models\Product;
use App\Services\Catalog\AttributeMigrationState;
use App\Services\Catalog\AttributeService;
use App\Services\Catalog\ProductAttributeEditor;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Gate;

/**
 * «Снять характеристику» (FL-18): removes one characteristic from the selected products, in chunks
 * ({@see AttributeService::removeAttributeFromProducts()}). Permission: `products.bulk`.
 */
class RemoveAttributeBulkAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'remove_attribute';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $editor = app(ProductAttributeEditor::class);

        $this
            ->label(__('admin_attributes.bulk.remove'))
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('danger')
            ->modalHeading(__('admin_attributes.bulk.remove'))
            ->modalDescription(__('admin_attributes.bulk.remove_description'))
            ->visible(fn (): bool => Gate::allows('bulk', Product::class))
            ->disabled(fn (): bool => app(AttributeMigrationState::class)->isPending())
            ->tooltip(fn (): ?string => app(AttributeMigrationState::class)->isPending() ? __('admin_attributes.bulk.migration_pending') : null)
            ->fetchSelectedRecords(false)
            ->deselectRecordsAfterCompletion()
            ->schema([
                Select::make('attribute_id')
                    ->label(__('admin_attributes.product.attribute'))
                    ->options(fn (): array => $editor->attributeOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $editor->attributeOptions($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => $editor->attributeLabel($value))
                    ->searchable()
                    ->native(false)
                    ->required(),
            ])
            ->action(function (HasTable $livewire, array $data): void {
                abort_unless(Gate::allows('bulk', Product::class), 403);

                $attribute = Attribute::query()->find((int) ($data['attribute_id'] ?? 0));

                if ($attribute === null) {
                    return;
                }

                $ids = array_map('intval', $livewire->getSelectedTableRecords(false)->all());

                try {
                    $count = app(AttributeService::class)->removeAttributeFromProducts($attribute, $ids);
                } catch (AttributeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_attributes.bulk.remove_done', ['count' => $count, 'name' => $attribute->name]))->send();
            });
    }
}
