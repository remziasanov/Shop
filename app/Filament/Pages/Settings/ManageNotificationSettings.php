<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Mail\TestMail;
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
            Section::make(__('shop.settings.notifications.sections.events'))->description(__('shop.settings.notifications.telegram_moved'))->schema([
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
                Toggle::make('notify_customer_order_changed')
                    ->label(__('shop.settings.notifications.fields.notify_customer_order_changed')),
                TextInput::make('order_discount_warning_percent')
                    ->label(__('shop.settings.notifications.fields.order_discount_warning_percent'))
                    ->helperText(__('shop.settings.notifications.fields.order_discount_warning_percent_help'))
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),
                Toggle::make('notify_reservation_expiring')
                    ->label(__('shop.settings.notifications.fields.notify_reservation_expiring')),
                Toggle::make('notify_reservation_expired')
                    ->label(__('shop.settings.notifications.fields.notify_reservation_expired')),
                Toggle::make('notify_wholesale_email')
                    ->label(__('shop.settings.notifications.fields.notify_wholesale_email')),
                Toggle::make('notify_wholesale_telegram')
                    ->label(__('shop.settings.notifications.fields.notify_wholesale_telegram')),
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

        return $data;
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
}
