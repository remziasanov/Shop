<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Settings\NotificationSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ManageNotificationSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    protected static string $settings = NotificationSettings::class;

    protected static string $translationKey = 'notifications';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?int $navigationSort = 50;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('shop.settings.notifications.sections.email'))->schema([
                Repeater::make('notify_emails')
                    ->label(__('shop.settings.notifications.fields.notify_emails'))
                    ->simple(
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                    )
                    ->addActionLabel(__('shop.settings.add'))
                    ->defaultItems(0),
            ]),
            Section::make(__('shop.settings.notifications.sections.telegram'))->schema([
                Toggle::make('telegram_enabled')
                    ->label(__('shop.settings.notifications.fields.telegram_enabled')),
                TextInput::make('telegram_bot_token')
                    ->label(__('shop.settings.notifications.fields.telegram_bot_token'))
                    ->password()
                    ->revealable()
                    ->autocomplete(false)
                    ->maxLength(255),
                TextInput::make('telegram_chat_id')
                    ->label(__('shop.settings.notifications.fields.telegram_chat_id'))
                    ->maxLength(64),
                Actions::make([
                    // Placeholder: sending is implemented in stage 6 (NT-04).
                    Action::make('sendTestMessage')
                        ->label(__('shop.settings.notifications.send_test'))
                        ->disabled()
                        ->tooltip(__('shop.settings.notifications.send_test_hint')),
                ]),
            ])->columns(2),
            Section::make(__('shop.settings.notifications.sections.events'))->schema([
                Toggle::make('notify_shop_email')
                    ->label(__('shop.settings.notifications.fields.notify_shop_email')),
                Toggle::make('notify_shop_telegram')
                    ->label(__('shop.settings.notifications.fields.notify_shop_telegram')),
                Toggle::make('notify_customer_new')
                    ->label(__('shop.settings.notifications.fields.notify_customer_new')),
                Toggle::make('notify_customer_status')
                    ->label(__('shop.settings.notifications.fields.notify_customer_status')),
                Toggle::make('notify_reservation_expiring')
                    ->label(__('shop.settings.notifications.fields.notify_reservation_expiring')),
                Toggle::make('notify_reservation_expired')
                    ->label(__('shop.settings.notifications.fields.notify_reservation_expired')),
            ])->columns(2),
        ])->columns(1);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['notify_emails'] = array_values($data['notify_emails'] ?? []);
        $data['telegram_bot_token'] = filled($data['telegram_bot_token'] ?? null) ? $data['telegram_bot_token'] : null;
        $data['telegram_chat_id'] = filled($data['telegram_chat_id'] ?? null) ? $data['telegram_chat_id'] : null;

        return $data;
    }
}
