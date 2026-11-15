<?php

declare(strict_types=1);

namespace App\Filament\Pages\Settings;

use App\Enums\PhoneVerificationBehavior;
use App\Enums\PhoneVerificationDriver;
use App\Enums\PhoneVerificationGuestScope;
use App\Enums\PhoneVerificationMode;
use App\Enums\PhoneVerificationRegistrationMode;
use App\Exceptions\PhoneProviderFailure;
use App\Exceptions\PhoneProviderRefusal;
use App\Exceptions\PhoneVerificationException;
use App\Filament\Concerns\AdminOnlySettingsPage;
use App\Models\User;
use App\Services\PhoneVerification\BalanceMonitor;
use App\Services\PhoneVerification\PhoneVerificationService;
use App\Services\PhoneVerification\SmsTemplate;
use App\Settings\PhoneVerificationSettings;
use App\Support\ShopName;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * «Настройки → Подтверждение телефона» (PV-01). The provider login and API key never reach the browser: the form state
 * holds neither, an empty field keeps the saved value («Изменить ключ»). The buttons use the saved settings.
 */
class ManagePhoneVerificationSettings extends SettingsPage
{
    use AdminOnlySettingsPage;

    /** Form keys saved from this page; the secrets and the balance marks are written by code only. */
    private const EDITABLE = [
        'enabled', 'driver', 'mode', 'sms_sender', 'sms_template', 'registration', 'profile_change', 'wholesale',
        'guest_checkout', 'guest_checkout_min_amount', 'allowed_prefixes', 'daily_limit', 'on_daily_limit',
        'on_provider_failure', 'balance_threshold', 'code_ttl_minutes',
    ];

    protected static string $settings = PhoneVerificationSettings::class;

    protected static string $translationKey = 'phone_verification';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?int $navigationSort = 56;

    public function form(Schema $schema): Schema
    {
        $money = 'regex:/^\d{1,9}([.,]\d{1,2})?$/';

        return $schema->components([
            Section::make(__('phone_verification.admin.sections.connection'))->schema([
                Toggle::make('enabled')->label(__('phone_verification.admin.fields.enabled')),
                Select::make('driver')
                    ->label(__('phone_verification.admin.fields.driver'))
                    ->options(fn (): array => PhoneVerificationDriver::options())
                    ->required()
                    ->native(false)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $driver = PhoneVerificationDriver::tryFrom((string) $value);

                        if ($driver === null || (app()->isProduction() && ! $driver->allowedInProduction())) {
                            $fail(__('phone_verification.admin.log_driver_production'));
                        }
                    }),
                Text::make(fn (): string => $this->stateLine())->columnSpanFull(),
                Toggle::make('change_credentials')
                    ->label(__('phone_verification.admin.fields.change_credentials'))
                    ->live()
                    ->dehydrated(false)
                    ->visible(fn (): bool => $this->credentialsAreSet()),
                TextInput::make('provider_login')
                    ->label(__('phone_verification.admin.fields.provider_login'))
                    ->autocomplete(false)
                    ->maxLength(255)
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => ! $this->credentialsAreSet() || (bool) $get('change_credentials')),
                TextInput::make('provider_api_key')
                    ->label(__('phone_verification.admin.fields.provider_api_key'))
                    ->password()
                    ->revealable(false)
                    ->autocomplete(false)
                    ->maxLength(255)
                    ->helperText(__('phone_verification.admin.fields.credentials_help'))
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => ! $this->credentialsAreSet() || (bool) $get('change_credentials')),
                Actions::make([
                    Action::make('checkConnection')
                        ->label(__('phone_verification.admin.actions.check'))
                        ->color('gray')
                        ->visible(fn (): bool => $this->canEdit())
                        ->action(fn () => $this->checkConnection()),
                    Action::make('sendTest')
                        ->label(__('phone_verification.admin.actions.test'))
                        ->color('gray')
                        ->visible(fn (): bool => $this->canEdit())
                        ->modalSubmitActionLabel(__('phone_verification.admin.actions.test_submit'))
                        ->schema([
                            TextInput::make('phone')
                                ->label(__('phone_verification.admin.actions.test_phone'))
                                ->tel()
                                ->required()
                                ->maxLength(32),
                        ])
                        ->action(fn (array $data) => $this->sendTest((string) $data['phone'])),
                ])->key('connectionActions'),
            ])->columns(2),
            Section::make(__('phone_verification.admin.sections.methods'))->schema([
                Select::make('mode')
                    ->label(__('phone_verification.admin.fields.mode'))
                    ->options(PhoneVerificationMode::options())
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('sms_sender')
                    ->label(__('phone_verification.admin.fields.sms_sender'))
                    ->helperText(__('phone_verification.admin.fields.sms_sender_help'))
                    ->regex('/^[A-Za-z0-9.\- ]{1,11}$/')
                    ->maxLength(11)
                    ->visible(fn (Get $get): bool => $get('mode') !== PhoneVerificationMode::FlashCall->value),
                Textarea::make('sms_template')
                    ->label(__('phone_verification.admin.fields.sms_template'))
                    ->helperText(fn (Get $get): string => __('phone_verification.admin.fields.sms_template_help').' '.__('phone_verification.admin.fields.sms_template_preview', [
                        'text' => SmsTemplate::render((string) $get('sms_template'), '1234', ShopName::get()),
                    ]))
                    ->required()
                    ->rows(2)
                    ->maxLength(200)
                    ->live(onBlur: true)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $error = SmsTemplate::validate((string) $value);

                        if ($error !== null) {
                            $fail(__('phone_verification.template.'.$error));
                        }
                    })
                    ->visible(fn (Get $get): bool => $get('mode') !== PhoneVerificationMode::FlashCall->value),
            ])->columns(2),
            Section::make(__('phone_verification.admin.sections.scenarios'))->schema([
                Select::make('registration')
                    ->label(__('phone_verification.admin.fields.registration'))
                    ->options(PhoneVerificationRegistrationMode::options())
                    ->required()
                    ->native(false),
                Toggle::make('profile_change')->label(__('phone_verification.admin.fields.profile_change')),
                Toggle::make('wholesale')->label(__('phone_verification.admin.fields.wholesale')),
                Select::make('guest_checkout')
                    ->label(__('phone_verification.admin.fields.guest_checkout'))
                    ->options(PhoneVerificationGuestScope::options())
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('guest_checkout_min_amount')
                    ->label(__('phone_verification.admin.fields.guest_checkout_min_amount'))
                    ->helperText(__('phone_verification.admin.fields.guest_checkout_min_amount_help'))
                    ->rule($money)
                    ->required()
                    ->visible(fn (Get $get): bool => $get('guest_checkout') === PhoneVerificationGuestScope::OrdersOver->value),
            ])->columns(2),
            Section::make(__('phone_verification.admin.sections.protection'))->schema([
                TagsInput::make('allowed_prefixes')
                    ->label(__('phone_verification.admin.fields.allowed_prefixes'))
                    ->helperText(__('phone_verification.admin.fields.allowed_prefixes_help'))
                    ->required()
                    ->nestedRecursiveRules(['regex:/^\+7\d{0,9}$/']),
                TextInput::make('daily_limit')
                    ->label(__('phone_verification.admin.fields.daily_limit'))
                    ->helperText(__('phone_verification.admin.fields.daily_limit_help'))
                    ->numeric()->integer()->minValue(0)->maxValue(100000)->required(),
                Select::make('on_daily_limit')
                    ->label(__('phone_verification.admin.fields.on_daily_limit'))
                    ->options(PhoneVerificationBehavior::options())
                    ->required()
                    ->native(false),
                Select::make('on_provider_failure')
                    ->label(__('phone_verification.admin.fields.on_provider_failure'))
                    ->helperText(__('phone_verification.admin.fields.on_provider_failure_help'))
                    ->options(PhoneVerificationBehavior::options())
                    ->required()
                    ->native(false),
                TextInput::make('balance_threshold')
                    ->label(__('phone_verification.admin.fields.balance_threshold'))
                    ->helperText(__('phone_verification.admin.fields.balance_threshold_help'))
                    ->rule($money)
                    ->required(),
                TextInput::make('code_ttl_minutes')
                    ->label(__('phone_verification.admin.fields.code_ttl_minutes'))
                    ->numeric()->integer()->minValue(1)->maxValue(30)->required(),
            ])->columns(2),
        ])->columns(1);
    }

    /**
     * The form never receives the login or the key; amounts are shown in roubles (stored in kopecks).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = array_intersect_key($data, array_flip(self::EDITABLE));
        $data['balance_threshold'] = $this->roubles((int) $data['balance_threshold']);
        $data['guest_checkout_min_amount'] = $this->roubles((int) $data['guest_checkout_min_amount']);

        return $data + ['provider_login' => null, 'provider_api_key' => null, 'change_credentials' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = array_intersect_key($data, array_flip(self::EDITABLE));
        $data['balance_threshold'] = $this->kopecks((string) $data['balance_threshold']);
        $data['guest_checkout_min_amount'] = $this->kopecks((string) ($data['guest_checkout_min_amount'] ?? '0'));
        $data['allowed_prefixes'] = array_values(array_unique(array_map(fn (mixed $prefix): string => trim((string) $prefix), (array) $data['allowed_prefixes'])));

        // A typed login or key replaces the saved one; an empty field keeps it. Same instance as the page saves.
        $settings = app(PhoneVerificationSettings::class);
        $login = trim((string) ($this->data['provider_login'] ?? ''));
        $key = trim((string) ($this->data['provider_api_key'] ?? ''));

        if ($login !== '') {
            $settings->provider_login = $login;
        }

        if ($key !== '') {
            $settings->provider_api_key = $key;
        }

        $needsCredentials = $data['driver'] === PhoneVerificationDriver::SmsAero->value;

        if ($data['enabled'] && $needsCredentials && (blank($settings->provider_login) || blank($settings->provider_api_key))) {
            throw ValidationException::withMessages(['data.enabled' => __('phone_verification.admin.notifications.credentials_required')]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->data['provider_login'] = null;
        $this->data['provider_api_key'] = null;
        $this->data['change_credentials'] = false;
    }

    private function credentialsAreSet(): bool
    {
        $settings = app(PhoneVerificationSettings::class);

        return filled($settings->provider_login) && filled($settings->provider_api_key);
    }

    private function stateLine(): string
    {
        $settings = app(PhoneVerificationSettings::class);
        $lines = [__('phone_verification.admin.state.'.($this->credentialsAreSet() ? 'credentials_set' : 'credentials_missing'))];
        $lines[] = __('phone_verification.admin.state.usage', ['used' => app(PhoneVerificationService::class)->dailyUsage(), 'limit' => $settings->daily_limit]);
        $lines[] = $settings->last_balance === null
            ? __('phone_verification.admin.state.no_balance')
            : __('phone_verification.admin.state.balance', [
                'balance' => $settings->last_balance,
                'time' => $settings->last_balance_at !== null ? CarbonImmutable::parse($settings->last_balance_at)->format('d.m.Y H:i') : '—',
            ]);

        return implode(' · ', $lines);
    }

    /** PV-01 «Проверить подключение»: a balance request with the saved credentials. */
    private function checkConnection(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        try {
            $balance = app(BalanceMonitor::class)->refresh();
        } catch (PhoneProviderFailure $e) {
            Notification::make()->danger()->title(__('phone_verification.admin.notifications.check_failed', ['reason' => __('phone_verification.failure_kinds.'.$e->kind->value)]))->send();

            return;
        } catch (PhoneProviderRefusal $e) {
            Notification::make()->danger()->title(__('phone_verification.admin.notifications.check_refused', ['reason' => $e->getMessage()]))->send();

            return;
        }

        Notification::make()->success()
            ->title($balance === null ? __('phone_verification.admin.notifications.check_ok_unknown') : __('phone_verification.admin.notifications.check_ok', ['balance' => $balance]))
            ->send();
    }

    /** PV-01 «Отправить тест на мой номер»: the same limits, the daily limit and the journal as a customer request. */
    private function sendTest(string $phone): void
    {
        $user = auth()->user();

        if (! $this->canEdit() || ! $user instanceof User) {
            return;
        }

        try {
            app(PhoneVerificationService::class)->sendTest($user, $phone, app('session.store'), (string) request()->ip());
        } catch (PhoneVerificationException $e) {
            Notification::make()->danger()->title(__('phone_verification.admin.notifications.test_failed', ['reason' => $e->getMessage()]))->send();

            return;
        }

        Notification::make()->success()->title(__('phone_verification.admin.notifications.test_sent'))->send();
    }

    private function roubles(int $kopecks): string
    {
        return rtrim(rtrim(bcdiv((string) $kopecks, '100', 2), '0'), '.');
    }

    private function kopecks(string $roubles): int
    {
        return (int) bcmul(str_replace(',', '.', trim($roubles)) ?: '0', '100', 0);
    }
}
