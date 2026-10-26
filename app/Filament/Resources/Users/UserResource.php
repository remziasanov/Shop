<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
            Select::make('role')
                ->label(__('shop.users.fields.role'))
                ->options(UserRole::options())
                ->default(UserRole::Manager->value)
                ->required()
                ->native(false),
            Toggle::make('is_active')
                ->label(__('shop.users.fields.is_active'))
                ->default(true),
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('shop.users.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('shop.users.fields.email'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label(__('shop.users.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole|string $state): string => ($state instanceof UserRole ? $state : UserRole::from($state))->label()),
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
                DeleteAction::make(),
            ])
            ->defaultSort('name');
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
