<?php

declare(strict_types=1);

namespace App\Filament\Resources\OptionTypes\RelationManagers;

use App\Enums\OptionDisplayType;
use App\Exceptions\OptionException;
use App\Filament\Resources\Products\Support\PhotoUpload;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\User;
use App\Services\Media\ImageProcessor;
use App\Services\Users\PermissionService;
use App\Services\Variants\OptionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Values of an option (VR-01, VR-26): add, edit with the colour and the swatch image, reorder by dragging, merge into
 * another value, delete (refused with «используется в N товарах»). Writes go through {@see OptionService}.
 */
class ValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'values';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin_variants.values.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('value')
                ->label(__('admin_variants.values.value'))
                ->required()
                ->maxLength(150),
            TextInput::make('slug')
                ->label(__('admin_variants.values.slug'))
                ->helperText(__('admin_variants.values.slug_hint'))
                ->maxLength(150),
            ColorPicker::make('hex')
                ->label(__('admin_variants.values.hex'))
                ->helperText(__('admin_variants.values.hex_hint'))
                ->visible(fn (): bool => $this->type()->display_type === OptionDisplayType::Color),
            FileUpload::make('swatch')
                ->label(__('admin_variants.values.swatch'))
                ->helperText(__('admin_variants.values.swatch_hint', ['size' => PhotoUpload::effectiveMaxLabel()]))
                ->image()
                ->maxSize(intdiv(PhotoUpload::effectiveMaxBytes(), 1024))
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
                ->disk('local')
                ->directory(OptionService::TEMP_DIRECTORY)
                ->visibility('private')
                ->visible(fn (): bool => $this->type()->display_type === OptionDisplayType::Color),
            Toggle::make('remove_swatch')
                ->label(__('admin_variants.values.remove_swatch'))
                ->visible(fn (Get $get, ?OptionValue $record): bool => $record?->image_path !== null),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('value')
            ->reorderable('sort_order', fn (): bool => $this->canManage())
            ->defaultSort('sort_order')
            ->afterReordering(function (array $order): void {
                abort_unless($this->canManage(), 403);

                app(OptionService::class)->reorderValues($this->type(), array_map('intval', $order));
            })
            ->columns([
                ImageColumn::make('image_path')
                    ->label(__('admin_variants.values.swatch'))
                    ->disk(ImageProcessor::DISK)
                    ->imageHeight(32)
                    ->square(),
                ColorColumn::make('hex')
                    ->label(__('admin_variants.values.hex')),
                TextColumn::make('value')
                    ->label(__('admin_variants.values.value'))
                    ->searchable(),
                TextColumn::make('slug')
                    ->label(__('admin_variants.values.slug'))
                    ->color('gray'),
                TextColumn::make('products_count')
                    ->label(__('admin_variants.values.products_count'))
                    ->alignEnd()
                    ->state(fn (OptionValue $record): int => $this->usage($record)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin_variants.values.add'))
                    ->visible(fn (): bool => $this->canManage())
                    ->modalHeading(__('admin_variants.values.add'))
                    ->using(function (array $data): Model {
                        abort_unless($this->canManage(), 403);

                        try {
                            return app(OptionService::class)->createValue($this->type(), $data);
                        } catch (OptionException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            throw new Halt;
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canManage())
                    ->using(function (OptionValue $record, array $data): Model {
                        abort_unless($this->canManage(), 403);

                        try {
                            return app(OptionService::class)->updateValue($record, $data);
                        } catch (OptionException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            throw new Halt;
                        }
                    }),
                $this->mergeAction(),
                $this->deleteAction(),
            ]);
    }

    /**
     * «Объединить значения» (VR-26): the variants and photos of this value move to the chosen one.
     */
    private function mergeAction(): Action
    {
        return Action::make('merge')
            ->label(__('admin_variants.values.merge'))
            ->icon(Heroicon::OutlinedArrowsPointingIn)
            ->color('gray')
            ->visible(fn (): bool => $this->canManage())
            ->modalHeading(fn (OptionValue $record): string => __('admin_variants.values.merge_heading', ['value' => $record->value]))
            ->modalDescription(__('admin_variants.values.merge_description'))
            ->schema([
                Select::make('target')
                    ->label(__('admin_variants.values.merge_target'))
                    ->options(fn (OptionValue $record): array => OptionValue::query()
                        ->where('option_type_id', $record->option_type_id)
                        ->whereKeyNot($record->id)
                        ->orderBy('sort_order')->orderBy('id')
                        ->limit(500)
                        ->pluck('value', 'id')
                        ->all())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->action(function (OptionValue $record, array $data): void {
                abort_unless($this->canManage(), 403);

                $target = OptionValue::query()->find((int) $data['target']);

                if ($target === null) {
                    return;
                }

                try {
                    $count = app(OptionService::class)->mergeValues($record, $target);
                } catch (OptionException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_variants.values.merged', ['count' => $count, 'target' => $target->value]))->send();
            });
    }

    /**
     * Refuses to delete a value that variants use, with the number of cards (VR-26).
     */
    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('filament-actions::delete.single.label'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->canManage())
            ->action(function (OptionValue $record): void {
                abort_unless($this->canManage(), 403);

                try {
                    app(OptionService::class)->deleteValue($record);
                } catch (OptionException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_variants.values.deleted'))->send();
            });
    }

    /** @var array<int, int>|null */
    private ?array $usages = null;

    private function usage(OptionValue $value): int
    {
        $this->usages ??= app(OptionService::class)->valueUsages(OptionValue::query()->where('option_type_id', $this->type()->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        return $this->usages[$value->id] ?? 0;
    }

    private function type(): OptionType
    {
        /** @var OptionType $owner */
        $owner = $this->getOwnerRecord();

        return $owner;
    }

    private function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, 'option_types.update');
    }
}
