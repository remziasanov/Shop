<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Enums\AttributeType;
use App\Exceptions\AttributeException;
use App\Models\Attribute;
use App\Models\Product;
use App\Services\Catalog\AttributeService;
use App\Services\Catalog\ProductAttributeEditor;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Gate;

/**
 * «Задать характеристику» (FL-18): the same value(s) of one characteristic for the selected products. The work is
 * {@see AttributeService::setAttributeForProducts()}, which writes in chunks; nothing goes to the import journal.
 * Permission: `products.bulk`.
 */
class SetAttributeBulkAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'set_attribute';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $editor = app(ProductAttributeEditor::class);

        $this
            ->label(__('admin_attributes.bulk.set'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->modalHeading(__('admin_attributes.bulk.set'))
            ->modalDescription(__('admin_attributes.bulk.set_description'))
            ->visible(fn (): bool => Gate::allows('bulk', Product::class))
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
                    ->required()
                    ->live(),
                TagsInput::make('list')
                    ->label(__('admin_attributes.product.values'))
                    ->helperText(__('admin_attributes.product.values_hint'))
                    ->suggestions(fn (Get $get): array => $editor->valueSuggestions($get('attribute_id')))
                    ->visible(fn (Get $get): bool => $editor->typeOf($get('attribute_id')) === AttributeType::List),
                TextInput::make('number')
                    ->label(__('admin_attributes.product.number'))
                    ->inputMode('decimal')
                    ->maxLength(30)
                    ->suffix(fn (Get $get): ?string => $editor->unitOf($get('attribute_id')))
                    ->visible(fn (Get $get): bool => $editor->typeOf($get('attribute_id')) === AttributeType::Number),
                Toggle::make('boolean')
                    ->label(__('admin_attributes.product.boolean'))
                    ->default(true)
                    ->visible(fn (Get $get): bool => $editor->typeOf($get('attribute_id')) === AttributeType::Boolean),
            ])
            ->action(function (HasTable $livewire, array $data): void {
                abort_unless(Gate::allows('bulk', Product::class), 403);

                $attribute = Attribute::query()->find((int) ($data['attribute_id'] ?? 0));

                if ($attribute === null) {
                    return;
                }

                $values = match ($attribute->type) {
                    AttributeType::List => array_values(array_filter(array_map('strval', (array) ($data['list'] ?? [])), fn (string $v): bool => trim($v) !== '')),
                    AttributeType::Number => $data['number'] ?? null,
                    AttributeType::Boolean => (bool) ($data['boolean'] ?? false),
                };

                // Empty values would remove the characteristic: that is «Снять характеристику», not this action.
                if ($values === [] || $values === null || (is_string($values) && trim($values) === '')) {
                    Notification::make()->danger()->title(__('admin_attributes.bulk.value_required', ['name' => $attribute->name]))->send();

                    return;
                }

                $ids = array_map('intval', $livewire->getSelectedTableRecords(false)->all());

                try {
                    $count = app(AttributeService::class)->setAttributeForProducts($attribute, $values, $ids);
                } catch (AttributeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_attributes.bulk.set_done', ['count' => $count, 'name' => $attribute->name]))->send();
            });
    }
}
