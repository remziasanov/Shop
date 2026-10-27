<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Mail\TestMail;
use App\Services\Notifications\HtmlEscaper;
use App\Services\Notifications\TelegramNotifier;
use App\Settings\GeneralSettings;
use App\Settings\NotificationSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use Throwable;

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
                Actions::make([
                    Action::make('sendTestMail')
                        ->label(__('shop.settings.notifications.send_test_mail'))
                        ->action(fn () => $this->sendTestMail()),
                ])->key('testMailActions'),
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
                // The saved token is never sent to the browser: the field stays empty and an empty save keeps it.
                TextInput::make('telegram_bot_token')
                    ->label(__('shop.settings.notifications.fields.telegram_bot_token'))
                    ->password()
                    ->autocomplete(false)
                    ->maxLength(255)
                    ->hint(fn (): ?string => filled(app(NotificationSettings::class)->telegram_bot_token) ? __('shop.settings.notifications.token_is_set') : null)
                    ->helperText(__('shop.settings.notifications.token_hint')),
                TextInput::make('telegram_chat_id')
                    ->label(__('shop.settings.notifications.fields.telegram_chat_id'))
                    ->maxLength(64),
                Toggle::make('remove_telegram_token')
                    ->label(__('shop.settings.notifications.fields.remove_telegram_token'))
                    ->visible(fn (): bool => filled(app(NotificationSettings::class)->telegram_bot_token)),
                Actions::make([
                    Action::make('sendTestMessage')
                        ->label(__('shop.settings.notifications.send_test'))
                        ->tooltip(__('shop.settings.notifications.test_hint'))
                        ->action(fn () => $this->sendTestTelegram()),
                ])->key('testTelegramActions'),
            ])->columns(2),
            Section::make(__('shop.settings.notifications.sections.events'))->schema([
                Toggle::make('notify_event_order')
                    ->label(__('shop.settings.notifications.fields.notify_event_order')),
                Toggle::make('notify_event_reservation')
                    ->label(__('shop.settings.notifications.fields.notify_event_reservation')),
                Toggle::make('notify_event_inquiry')
                    ->label(__('shop.settings.notifications.fields.notify_event_inquiry')),
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
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['telegram_bot_token'] = null;
        $data['remove_telegram_token'] = false;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['notify_emails'] = array_values($data['notify_emails'] ?? []);
        $data['telegram_chat_id'] = filled($data['telegram_chat_id'] ?? null) ? $data['telegram_chat_id'] : null;

        $remove = (bool) ($data['remove_telegram_token'] ?? false);
        unset($data['remove_telegram_token']);

        if ($remove) {
            $data['telegram_bot_token'] = null;
        } elseif (blank($data['telegram_bot_token'] ?? null)) {
            // An empty field keeps the existing token.
            $data['telegram_bot_token'] = app(NotificationSettings::class)->telegram_bot_token;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        // Do not keep a typed token in the component state after it has been stored.
        $this->data['telegram_bot_token'] = null;
        $this->data['remove_telegram_token'] = false;
    }

    private function sendTestMail(): void
    {
        $recipients = array_values(array_filter(array_map(
            fn (mixed $row): ?string => is_array($row) ? ($row['email'] ?? null) : (is_string($row) ? $row : null),
            (array) ($this->data['notify_emails'] ?? []),
        )));
        $to = $recipients[0] ?? auth()->user()?->email;

        if (blank($to)) {
            Notification::make()->danger()->title(__('notifications.test.mail_no_recipient'))->send();

            return;
        }

        try {
            Mail::to($to)->send(new TestMail);
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('notifications.test.mail_failed', ['error' => $e->getMessage()]))->send();

            return;
        }

        Notification::make()->success()->title(__('notifications.test.mail_sent', ['email' => $to]))->send();
    }

    private function sendTestTelegram(): void
    {
        $token = filled($this->data['telegram_bot_token'] ?? null) ? (string) $this->data['telegram_bot_token'] : null;
        $chatId = filled($this->data['telegram_chat_id'] ?? null) ? (string) $this->data['telegram_chat_id'] : null;

        try {
            app(TelegramNotifier::class)->send(
                HtmlEscaper::e(__('notifications.telegram.test', ['shop' => app(GeneralSettings::class)->shop_name])),
                $token,
                $chatId,
            );
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('notifications.test.telegram_failed', ['error' => $e->getMessage()]))->send();

            return;
        }

        Notification::make()->success()->title(__('notifications.test.telegram_sent'))->send();
    }
}
