<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Exceptions\StaffAccessException;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\RoleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * «Роли сотрудников» under Настройки (RB-09): list with user and permission counts, permission matrix form,
 * copying. Every change goes through {@see RoleService}.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 91;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('roles.resource.plural');
    }

    public static function getModelLabel(): string
    {
        return __('roles.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('roles.resource.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('users')
                ->selectRaw('(select count(*) from role_permissions where role_permissions.role_id = roles.id) as permissions_count')
                ->addSelect('roles.*'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('roles.fields.name'))
                    ->description(fn (Role $record): ?string => $record->description)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('roles.fields.code'))
                    ->badge()
                    ->color(fn (Role $record): string => $record->is_system ? 'gray' : 'info')
                    ->tooltip(fn (Role $record): ?string => $record->is_system ? __('roles.table.system') : null),
                TextColumn::make('users_count')
                    ->label(__('roles.table.users'))
                    ->numeric()
                    ->sortable(),
                TextColumn::make('permissions_count')
                    ->label(__('roles.table.permissions'))
                    ->state(fn (Role $record): string => $record->isAdministrator() ? __('roles.table.all') : (string) $record->permissions_count),
            ])
            ->recordActions([
                EditAction::make(),
                self::duplicateAction(),
                DeleteAction::make()
                    ->using(fn (Role $record): bool => self::deleteRecord($record)),
            ])
            ->defaultSort('id');
    }

    public static function duplicateAction(): Action
    {
        return Action::make('duplicate')
            ->label(__('roles.actions.duplicate'))
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->visible(fn (): bool => auth()->user()?->can('create', Role::class) ?? false)
            ->action(function (Role $record): void {
                $actor = auth()->user();

                try {
                    $copy = app(RoleService::class)->duplicate($record, $actor instanceof User ? $actor : abort(403));
                } catch (StaffAccessException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('roles.notifications.copied', ['name' => $copy->name]))->send();
            });
    }

    /**
     * Deletion through the service: system roles and roles with users are refused with a message (RB-07).
     */
    public static function deleteRecord(Role $record): bool
    {
        $actor = auth()->user();

        try {
            app(RoleService::class)->delete($record, $actor instanceof User ? $actor : abort(403));
        } catch (StaffAccessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        }

        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
