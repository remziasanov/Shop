<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Enums\SystemRole;
use App\Exceptions\StaffAccessException;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\AdminGuard;
use App\Services\Users\EscalationGuard;
use App\Services\Users\StaffAccountService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 90;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.settings');
    }

    public static function getModelLabel(): string
    {
        return __('shop.users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('shop.users.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('shop.users.fields.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label(__('shop.users.fields.email'))
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            Select::make('role_id')
                ->label(__('shop.users.fields.role'))
                ->options(fn (?User $record): array => static::roleOptions($record))
                ->default(fn (): ?int => static::defaultRoleId())
                ->required()
                ->native(false)
                ->disabled(fn (?User $record): bool => static::isLocked($record))
                ->dehydrated(fn (?User $record): bool => ! static::isLocked($record))
                ->helperText(fn (?User $record): ?string => static::isLocked($record) ? __('shop.users.locked_hint') : null),
            Toggle::make('is_active')
                ->label(__('shop.users.fields.is_active'))
                ->default(true)
                ->disabled(fn (?User $record): bool => static::isLocked($record))
                ->dehydrated(fn (?User $record): bool => ! static::isLocked($record)),
            TextInput::make('password')
                ->label(__('shop.users.fields.password'))
                ->password()
                ->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->rule(Password::min(10))
                ->confirmed()
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(fn (string $operation): string => $operation === 'edit'
                    ? __('shop.users.password_hint_edit')
                    : __('shop.users.password_hint')),
            TextInput::make('password_confirmation')
                ->label(__('shop.users.fields.password_confirmation'))
                ->password()
                ->revealable()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(false),
        ])->columns(2);
    }

    /**
     * Roles the signed-in staff member may assign (RB-08), plus the role the record already has.
     *
     * @return array<int, string>
     */
    public static function roleOptions(?User $record): array
    {
        $actor = auth()->user();
        $roles = $actor instanceof User ? app(EscalationGuard::class)->assignableRoles($actor) : new Collection;
        $options = $roles->pluck('name', 'id')->all();

        if ($record !== null && $record->role_id !== null && ! isset($options[$record->role_id])) {
            $options[$record->role_id] = (string) Role::query()->whereKey($record->role_id)->value('name');
        }

        return $options;
    }

    protected static function defaultRoleId(): ?int
    {
        $id = Role::query()->where('code', SystemRole::Manager->value)->value('id');

        return $id !== null ? (int) $id : null;
    }

    public static function isLocked(?User $record): bool
    {
        $actor = auth()->user();

        return $record !== null && $record->exists
            && app(AdminGuard::class)->isLocked($record, $actor instanceof User ? $actor : null);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('role:id,name'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('shop.users.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('shop.users.fields.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role.name')
                    ->label(__('shop.users.fields.role'))
                    ->badge()
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label(__('shop.users.fields.is_active'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('shop.users.fields.created_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->using(fn (User $record) => static::deleteRecord($record)),
            ])
            ->defaultSort('name');
    }

    /**
     * Deletion through the service: rights, escalation, last administrator and the audit entry (RB-07, RB-12).
     */
    public static function deleteRecord(User $record): bool
    {
        $actor = auth()->user();

        try {
            app(StaffAccountService::class)->delete($record, $actor instanceof User ? $actor : abort(403));
        } catch (StaffAccessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return false;
        }

        return true;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
