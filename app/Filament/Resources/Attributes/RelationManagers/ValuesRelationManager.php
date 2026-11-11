<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attributes\RelationManagers;

use App\Enums\AttributeType;
use App\Exceptions\AttributeException;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Catalog\AttributeService;
use App\Services\Users\PermissionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Values of a list characteristic (FL-02): add, rename, reorder by dragging, merge into another value, delete
 * (refused while products use the value). Writes go through {@see AttributeService}.
 */
class ValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'values';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin_attributes.values.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Attribute
            && $ownerRecord->type === AttributeType::List
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('value')
                ->label(__('admin_attributes.values.value'))
                ->required()
                ->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->select('attribute_values.*')
                ->addSelect(['products_count' => ProductAttributeValue::query()
                    ->selectRaw('count(distinct product_id)')
                    ->whereColumn('product_attribute_values.attribute_value_id', 'attribute_values.id')]))
            ->recordTitleAttribute('value')
            ->reorderable('sort_order', fn (): bool => $this->canManage())
            ->defaultSort('sort_order')
            ->afterReordering(function (array $order): void {
                app(AttributeService::class)->reorderValues($this->attribute(), array_map('intval', $order));
            })
            ->columns([
                TextColumn::make('value')
                    ->label(__('admin_attributes.values.value'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('admin_attributes.values.slug'))
                    ->color('gray'),
                TextColumn::make('products_count')
                    ->label(__('admin_attributes.values.products_count'))
                    ->alignEnd()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin_attributes.values.add'))
                    ->visible(fn (): bool => $this->canManage())
                    ->modalHeading(__('admin_attributes.values.add'))
                    ->using(function (array $data): Model {
                        abort_unless($this->canManage(), 403);

                        try {
                            return app(AttributeService::class)->createValue($this->attribute(), (string) $data['value']);
                        } catch (AttributeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canManage())
                    ->using(function (AttributeValue $record, array $data): Model {
                        abort_unless($this->canManage(), 403);

                        try {
                            return app(AttributeService::class)->renameValue($record, (string) $data['value']);
                        } catch (AttributeException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            throw new Halt;
                        }
                    }),
                $this->mergeAction(),
                $this->deleteAction(),
            ]);
    }

    /**
     * «Объединить значения» (FL-02): products of this value move to the chosen one and this value is removed.
     */
    private function mergeAction(): Action
    {
        return Action::make('merge')
            ->label(__('admin_attributes.values.merge'))
            ->icon(Heroicon::OutlinedArrowsPointingIn)
            ->color('gray')
            ->visible(fn (): bool => $this->canManage())
            ->modalHeading(fn (AttributeValue $record): string => __('admin_attributes.values.merge_heading', ['value' => $record->value]))
            ->modalDescription(__('admin_attributes.values.merge_description'))
            ->schema([
                Select::make('target')
                    ->label(__('admin_attributes.values.merge_target'))
                    ->options(fn (AttributeValue $record): array => AttributeValue::query()
                        ->where('attribute_id', $record->attribute_id)
                        ->whereKeyNot($record->id)
                        ->orderBy('sort_order')->orderBy('id')
                        ->limit(500)
                        ->pluck('value', 'id')
                        ->all())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(function (AttributeValue $record, array $data): void {
                abort_unless($this->canManage(), 403);

                $target = AttributeValue::query()->find((int) $data['target']);

                if ($target === null) {
                    return;
                }

                try {
                    $count = app(AttributeService::class)->mergeValues($record, $target);
                } catch (AttributeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_attributes.values.merged', ['count' => $count, 'target' => $target->value]))->send();
            });
    }

    /**
     * Refuses to delete a value that products use, with their number (FL-02).
     */
    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('filament-actions::delete.single.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManage())
            ->action(function (AttributeValue $record): void {
                abort_unless($this->canManage(), 403);

                try {
                    app(AttributeService::class)->deleteValue($record);
                } catch (AttributeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_attributes.values.deleted'))->send();
            });
    }

    private function attribute(): Attribute
    {
        /** @var Attribute $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }

    private function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, 'attributes.update');
    }
}
