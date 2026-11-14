<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Services\Telegram\TelegramBotSetup;
use App\Settings\TelegramSettings;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * «Настройки → Telegram» (TG-01…TG-03, TG-11): the single page of the shop bot. The token and the webhook secret never
 * reach the browser: the form state holds neither, an empty token field keeps the saved token.
 */
class ManageTelegramSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    /** Form keys saved from this page; everything else (identity, marks, secrets) is written by services only. */
    private const EDITABLE = [
        'enabled', 'work_chat_id', 'menu_button_text', 'welcome_text', 'help_text', 'miniapp_enabled', 'autologin_enabled',
        'customer_notifications_enabled', 'work_chat_buttons_enabled', 'staff_workplace_enabled', 'miniapp_colors',
        'initdata_ttl_customer', 'initdata_ttl_staff',
    ];

    protected static string $settings = TelegramSettings::class;

    protected static string $translationKey = 'telegram';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    protected static ?int $navigationSort = 55;

    /** Result rows of the last «Подключить бота» / «Отключить». @var list<array{key: string, ok: bool, detail: string}> */
    public array $steps = [];

    /** Result of the last «Проверить» (getWebhookInfo). @var array{url: string, pending: int, last_error: ?string, last_error_at: ?string}|null */
    public ?array $webhook = null;

    public ?string $webhookError = null;

    public function form(Schema $schema): Schema
    {
        $setup = app(TelegramBotSetup::class);

        return $schema->components([
            Section::make(__('telegram.admin.sections.connection'))->schema([
                View::make('filament.settings.telegram-state')
                    ->viewData(fn (): array => $this->stateData())
                    ->columnSpanFull(),
                Toggle::make('change_token')
                    ->label(__('telegram.admin.fields.change_token'))
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->tokenIsSet()),
                TextInput::make('bot_token')
                    ->label(__('telegram.admin.fields.bot_token'))
                    ->password()
                    ->revealable(false)
                    ->autocomplete(false)
                    ->maxLength(255)
                    ->helperText(__('telegram.admin.fields.bot_token_help'))
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => ! $this->tokenIsSet() || (bool) $get('change_token')),
                TextInput::make('work_chat_id')
                    ->label(__('telegram.admin.fields.work_chat_id'))
                    ->helperText(__('telegram.admin.fields.work_chat_id_help'))
                    ->maxLength(64),
                Toggle::make('enabled')
                    ->label(__('telegram.admin.fields.enabled'))
                    ->helperText(__('telegram.admin.fields.enabled_help')),
                Actions::make([
                    Action::make('connectBot')
                        ->label(__('telegram.admin.actions.connect'))
                        ->disabled(! $setup->canConnect())
                        ->tooltip($setup->canConnect() ? null : __('telegram.admin.https_required'))
                        ->visible(fn (): bool => $this->canEdit())
                        ->action(fn () => $this->connectBot()),
                    Action::make('checkState')
                        ->label(__('telegram.admin.actions.check'))
                        ->color('gray')
                        ->visible(fn (): bool => $this->tokenIsSet())
                        ->action(fn () => $this->checkState()),
                    Action::make('disconnectBot')
                        ->label(__('telegram.admin.actions.disconnect'))
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (): bool => $this->canEdit() && app(TelegramBotSetup::class)->isConnected())
                        ->action(fn () => $this->disconnectBot()),
                    Action::make('sendWorkChatTest')
                        ->label(__('telegram.admin.actions.test'))
                        ->color('gray')
                        ->visible(fn (): bool => $this->canEdit() && $this->tokenIsSet())
                        ->action(fn () => $this->sendWorkChatTest()),
                ])->key('connectionActions'),
            ])->columns(2),
            Section::make(__('telegram.admin.sections.texts'))->schema([
                TextInput::make('menu_button_text')
                    ->label(__('telegram.admin.fields.menu_button_text'))
                    ->required()
                    ->maxLength(32),
                Textarea::make('welcome_text')
                    ->label(__('telegram.admin.fields.welcome_text'))
                    ->helperText(__('telegram.admin.fields.default_text_help'))
                    ->rows(3)
                    ->maxLength(1000),
                Textarea::make('help_text')
                    ->label(__('telegram.admin.fields.help_text'))
                    ->helperText(__('telegram.admin.fields.default_text_help'))
                    ->rows(3)
                    ->maxLength(1000),
            ])->columns(1),
            Section::make(__('telegram.admin.sections.features'))->schema([
                Toggle::make('miniapp_enabled')->label(__('telegram.admin.fields.miniapp_enabled')),
                Toggle::make('autologin_enabled')->label(__('telegram.admin.fields.autologin_enabled')),
                Toggle::make('customer_notifications_enabled')->label(__('telegram.admin.fields.customer_notifications_enabled')),
                Toggle::make('work_chat_buttons_enabled')->label(__('telegram.admin.fields.work_chat_buttons_enabled')),
                Toggle::make('staff_workplace_enabled')->label(__('telegram.admin.fields.staff_workplace_enabled')),
                Select::make('miniapp_colors')
                    ->label(__('telegram.admin.fields.miniapp_colors'))
                    ->options([
                        'shop' => __('telegram.admin.colors.shop'),
                        'telegram' => __('telegram.admin.colors.telegram'),
                    ])
                    ->required()
                    ->native(false),
                TextInput::make('initdata_ttl_customer')
                    ->label(__('telegram.admin.fields.initdata_ttl_customer'))
                    ->helperText(__('telegram.admin.fields.initdata_ttl_help'))
                    ->numeric()->integer()->minValue(60)->maxValue(86400)->required()->suffix(__('telegram.admin.seconds')),
                TextInput::make('initdata_ttl_staff')
                    ->label(__('telegram.admin.fields.initdata_ttl_staff'))
                    ->numeric()->integer()->minValue(60)->maxValue(86400)->required()->suffix(__('telegram.admin.seconds')),
            ])->columns(2),
            Section::make(__('telegram.admin.sections.startapp'))->schema([
                View::make('filament.settings.telegram-startapp')
                    ->viewData(fn (): array => [
                        'bot' => app(TelegramSettings::class)->bot_username,
                        'checkUrl' => app(TelegramBotSetup::class)->startappCheckUrl(),
                        'verified' => app(TelegramBotSetup::class)->startappVerified(),
                        'baseUrl' => app(TelegramBotSetup::class)->miniAppUrl(),
                    ])
                    ->columnSpanFull(),
            ]),
        ])->columns(1);
    }

    /**
     * The form never receives the token, the secret or the read-only service fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return array_intersect_key($data, array_flip(self::EDITABLE)) + ['bot_token' => null, 'change_token' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = array_intersect_key($data, array_flip(self::EDITABLE));
        $data['work_chat_id'] = filled($data['work_chat_id'] ?? null) ? trim((string) $data['work_chat_id']) : null;

        // TG-11: a typed token is checked and, when the bot is set up, the webhook and menu button are redone with it.
        $typed = trim((string) ($this->data['bot_token'] ?? ''));

        if ($typed !== '') {
            $this->applyToken($typed);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->data['bot_token'] = null;
        $this->data['change_token'] = false;
    }

    private function tokenIsSet(): bool
    {
        return filled(app(TelegramSettings::class)->bot_token);
    }

    /**
     * @return array<string, mixed>
     */
    private function stateData(): array
    {
        $settings = app(TelegramSettings::class);
        $setup = app(TelegramBotSetup::class);

        return [
            'tokenSet' => $this->tokenIsSet(),
            'connected' => $setup->isConnected(),
            'canConnect' => $setup->canConnect(),
            'botUsername' => $settings->bot_username,
            'botId' => $settings->bot_id,
            'webhookUrl' => $setup->webhookUrl(),
            'workChatError' => $settings->work_chat_error,
            'steps' => $this->steps,
            'webhook' => $this->webhook,
            'webhookError' => $this->webhookError,
        ];
    }

    /** TG-02. A typed token is used (and saved after getMe accepts it); otherwise the saved one. */
    private function connectBot(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $typed = trim((string) ($this->data['bot_token'] ?? ''));
        $this->steps = app(TelegramBotSetup::class)->connect($typed !== '' ? $typed : null);
        $this->data['bot_token'] = null;
        $this->data['change_token'] = false;
        $this->webhook = null;
        $this->webhookError = null;

        $failed = collect($this->steps)->contains(fn (array $step): bool => ! $step['ok']);

        Notification::make()
            ->{$failed ? 'danger' : 'success'}()
            ->title(__($failed ? 'telegram.admin.connect_failed' : 'telegram.admin.connect_done'))
            ->send();
    }

    private function applyToken(string $token): void
    {
        $setup = app(TelegramBotSetup::class);

        if ($setup->canConnect()) {
            $this->steps = $setup->connect($token);
            $failed = collect($this->steps)->contains(fn (array $step): bool => ! $step['ok']);

            Notification::make()
                ->{$failed ? 'danger' : 'success'}()
                ->title(__($failed ? 'telegram.admin.connect_failed' : 'telegram.admin.connect_done'))
                ->send();

            return;
        }

        // No https (local environment): the token is only stored; «Подключить бота» is unavailable anyway.
        app(TelegramSettings::class)->bot_token = $token;
    }

    private function checkState(): void
    {
        $this->webhook = null;
        $this->webhookError = null;

        try {
            $state = app(TelegramBotSetup::class)->webhookState();
            $this->webhook = [
                'url' => $state['url'],
                'pending' => $state['pending'],
                'last_error' => $state['last_error'],
                'last_error_at' => $state['last_error_at'] instanceof CarbonImmutable ? $state['last_error_at']->format('d.m.Y H:i') : null,
            ];
        } catch (Throwable $e) {
            $this->webhookError = $e->getMessage();
        }
    }

    private function disconnectBot(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $this->steps = app(TelegramBotSetup::class)->disconnect();
        $this->webhook = null;

        Notification::make()->success()->title(__('telegram.admin.disconnected'))->send();
    }

    private function sendWorkChatTest(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        try {
            app(TelegramBotSetup::class)->sendWorkChatTest();
        } catch (Throwable $e) {
            Notification::make()->danger()->title(__('notifications.test.telegram_failed', ['error' => $e->getMessage()]))->send();

            return;
        }

        Notification::make()->success()->title(__('notifications.test.telegram_sent'))->send();
    }
}
