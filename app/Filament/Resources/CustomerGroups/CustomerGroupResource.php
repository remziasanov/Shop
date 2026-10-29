<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomerGroups;

use App\Exceptions\CustomerGroupException;
use App\Filament\Resources\CustomerGroups\Pages\CreateCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\EditCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\ListCustomerGroups;
use App\Filament\Resources\CustomerGroups\Schemas\CustomerGroupForm;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Access\CustomerGroupService;
use App\Services\Access\ViewerPreview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * «Группы покупателей» under Продажи (CG-18): list, tabbed form, «Просмотреть витрину как…». Saving goes through
 * {@see CustomerGroupService}; abilities come from the `customer_groups.*` permissions (CustomerGroupPolicy).
 */
class CustomerGroupResource extends Resource
{
    protected static ?string $model = CustomerGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('customer_groups.resource.plural');
    }

    public static function getModelLabel(): string
    {
        return __('customer_groups.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('customer_groups.resource.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomerGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('customer_groups.fields.name'))
                    ->description(fn (CustomerGroup $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('customer_groups.fields.code'))
                    ->badge()
                    ->color(fn (CustomerGroup $record): string => $record->is_system ? 'gray' : 'info')
                    ->tooltip(fn (CustomerGroup $record): ?string => $record->is_system ? __('customer_groups.table.system') : null),
                TextColumn::make('category_access_mode')
                    ->label(__('customer_groups.fields.category_access_mode'))
                    ->state(fn (CustomerGroup $record): string => $record->category_access_mode->label()),
                TextColumn::make('price_display')
                    ->label(__('customer_groups.fields.price_display'))
                    ->state(fn (CustomerGroup $record): string => $record->price_display->label()),
                IconColumn::make('is_active')
                    ->label(__('customer_groups.fields.is_active'))
                    ->boolean(),
            ])
            ->recordActions([
                self::previewAction(),
                EditAction::make(),
                self::deleteAction(),
            ])
            ->recordUrl(fn (CustomerGroup $record): string => static::getUrl('edit', ['record' => $record]));
    }

    /**
     * «Просмотреть витрину как…» (CG-18): sets the preview flag in the staff session (a Livewire action, so CSRF
     * protected) and opens the storefront as the group.
     */
    public static function previewAction(): Action
    {
        return Action::make('preview')
            ->label(__('customer_groups.actions.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->action(function (CustomerGroup $record) {
                $user = auth()->user();

                app(ViewerPreview::class)->enter($user instanceof User ? $user : null, $record, app('session.store'));

                return redirect()->route('home');
            });
    }

    /**
     * «Удалить» (CG-25): hidden for the system groups (policy), otherwise asks which group receives the customers
     * («Розница» preselected). The work is {@see CustomerGroupService::delete()}.
     */
    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->modalHeading(fn (CustomerGroup $record): string => __('customer_groups.actions.delete_heading', ['name' => $record->name]))
            ->modalDescription(fn (CustomerGroup $record): string => __('customer_groups.actions.delete_description', ['count' => Customer::query()->where('customer_group_id', $record->id)->count()]))
            ->schema([
                Select::make('move_to')
                    ->label(__('customer_groups.actions.move_to'))
                    ->options(fn (CustomerGroup $record): array => self::moveTargets($record))
                    ->default(fn (): ?int => CustomerGroup::query()->where('code', CustomerGroup::RETAIL)->value('id'))
                    ->required()
                    ->native(false),
            ])
            ->using(fn (CustomerGroup $record, array $data): bool => self::deleteRecord($record, isset($data['move_to']) ? (int) $data['move_to'] : null));
    }

    /**
     * Groups that can receive the customers of a deleted group: active, not «Гость», not the group itself.
     *
     * @return array<int, string>
     */
    public static function moveTargets(CustomerGroup $group): array
    {
        return CustomerGroup::query()
            ->where('is_active', true)
            ->where('code', '!=', CustomerGroup::GUEST)
            ->whereKeyNot($group->id)
            ->ordered()
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Deletion through the service: the system groups and a wrong target are refused with a message (CG-01, CG-25).
     */
    public static function deleteRecord(CustomerGroup $record, ?int $moveToId = null): bool
    {
        try {
            $target = $moveToId === null ? null : CustomerGroup::query()->find($moveToId);
            app(CustomerGroupService::class)->delete($record, $target);
        } catch (CustomerGroupException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        }

        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerGroups::route('/'),
            'create' => CreateCustomerGroup::route('/create'),
            'edit' => EditCustomerGroup::route('/{record}/edit'),
        ];
    }
}
