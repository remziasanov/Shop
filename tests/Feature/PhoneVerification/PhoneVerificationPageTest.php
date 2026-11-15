<?php

declare(strict_types=1);

use App\Enums\PhoneProviderFailureKind;
use App\Filament\Pages\Settings\ManagePhoneVerificationSettings;
use App\Models\AdminAuditEntry;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Settings\PhoneVerificationSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * PV-01: «Настройки → Подтверждение телефона».
 */
beforeEach(function (): void {
    $this->editor = staffWith(['settings.phone_verification.update']);
    $this->actingAs($this->editor);
});

it('opens by view and edits by update only; the Manager has no access', function (): void {
    $this->actingAs(User::factory()->manager()->create());
    expect(ManagePhoneVerificationSettings::canAccess())->toBeFalse();

    $this->actingAs(staffWith(['settings.phone_verification.view']));
    expect(ManagePhoneVerificationSettings::canAccess())->toBeTrue()->and((new ManagePhoneVerificationSettings)->canEdit())->toBeFalse();

    $this->actingAs($this->editor);
    expect((new ManagePhoneVerificationSettings)->canEdit())->toBeTrue();
});

it('never sends the login or the key to the browser', function (): void {
    enablePhoneVerification();

    $component = Livewire::test(ManagePhoneVerificationSettings::class);

    expect($component->html())->not->toContain('TEST-api-key-123')->not->toContain('owner@example.com')
        ->and(json_encode($component->snapshot))->not->toContain('TEST-api-key-123')->not->toContain('owner@example.com');
    $component->assertFormFieldHidden('provider_api_key')->assertFormFieldHidden('provider_login')->assertFormFieldExists('change_credentials');
});

it('keeps the saved credentials on an empty save and replaces them on «Изменить ключ»', function (): void {
    enablePhoneVerification();

    Livewire::test(ManagePhoneVerificationSettings::class)
        ->fillForm(['daily_limit' => 150])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(PhoneVerificationSettings::class);
    expect($settings->provider_api_key)->toBe('TEST-api-key-123')->and($settings->provider_login)->toBe('owner@example.com')->and($settings->daily_limit)->toBe(150);

    Livewire::test(ManagePhoneVerificationSettings::class)
        ->fillForm(['change_credentials' => true, 'provider_api_key' => 'NEW-key-456'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(PhoneVerificationSettings::class)->provider_api_key)->toBe('NEW-key-456')->and(app(PhoneVerificationSettings::class)->provider_login)->toBe('owner@example.com');
});

it('saves the settings, converting roubles to kopecks', function (): void {
    enablePhoneVerification();

    Livewire::test(ManagePhoneVerificationSettings::class)
        ->fillForm([
            'mode' => 'flash_call_then_sms', 'sms_sender' => 'AlevShop', 'registration' => 'later', 'profile_change' => true, 'wholesale' => true,
            'guest_checkout' => 'orders_over', 'guest_checkout_min_amount' => '5000', 'allowed_prefixes' => ['+79', '+77'],
            'on_daily_limit' => 'skip', 'on_provider_failure' => 'block', 'balance_threshold' => '250,50', 'code_ttl_minutes' => 3,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(PhoneVerificationSettings::class);
    expect($settings->mode)->toBe('flash_call_then_sms')
        ->and($settings->registration)->toBe('later')
        ->and($settings->guest_checkout)->toBe('orders_over')
        ->and($settings->guest_checkout_min_amount)->toBe(500000)
        ->and($settings->balance_threshold)->toBe(25050)
        ->and($settings->allowed_prefixes)->toBe(['+79', '+77'])
        ->and($settings->on_daily_limit)->toBe('skip')
        ->and($settings->on_provider_failure)->toBe('block')
        ->and($settings->code_ttl_minutes)->toBe(3);
});

it('refuses an SMS text that does not fit one SMS, has no code or holds a link', function (): void {
    enablePhoneVerification();

    foreach ([str_repeat('а', 90).' {code}', 'Ваш код подтверждения', 'Код {code} на https://example.ru'] as $template) {
        Livewire::test(ManagePhoneVerificationSettings::class)
            ->fillForm(['mode' => 'sms', 'sms_template' => $template])
            ->call('save')
            ->assertHasFormErrors(['sms_template']);
    }

    expect(app(PhoneVerificationSettings::class)->sms_template)->toBe('Код подтверждения: {code}. {shop}');

    // A long shop name does not stop the save: it is shortened when the SMS goes out.
    Livewire::test(ManagePhoneVerificationSettings::class)
        ->fillForm(['mode' => 'sms', 'sms_template' => 'Код {code}. {shop}'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('refuses wrong prefixes', function (): void {
    enablePhoneVerification();

    Livewire::test(ManagePhoneVerificationSettings::class)
        ->fillForm(['allowed_prefixes' => ['8999']])
        ->call('save')
        ->assertHasFormErrors(['allowed_prefixes.0']);
});

it('does not offer the log driver in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    Livewire::test(ManagePhoneVerificationSettings::class)
        ->assertFormFieldExists('driver', fn (Select $field): bool => array_keys($field->getOptions()) === ['sms_aero']);
});

it('does not switch on without credentials', function (): void {
    Livewire::test(ManagePhoneVerificationSettings::class)
        ->fillForm(['enabled' => true])
        ->call('save')
        ->assertHasErrors(['data.enabled']);

    expect(app(PhoneVerificationSettings::class)->enabled)->toBeFalse();
});

it('«Проверить подключение» asks for the balance and remembers it', function (): void {
    enablePhoneVerification();
    phoneStub()->balance = '312.50';

    Livewire::test(ManagePhoneVerificationSettings::class)
        ->callAction(TestAction::make('checkConnection')->schemaComponent('connectionActions', schema: 'form'))
        ->assertNotified(__('phone_verification.admin.notifications.check_ok', ['balance' => '312.50']));

    expect(app(PhoneVerificationSettings::class)->last_balance)->toBe('312.50');
});

it('«Проверить подключение» reports a provider failure', function (): void {
    enablePhoneVerification();
    phoneStub()->failWith(PhoneProviderFailureKind::Auth);

    Livewire::test(ManagePhoneVerificationSettings::class)
        ->callAction(TestAction::make('checkConnection')->schemaComponent('connectionActions', schema: 'form'))
        ->assertNotified(__('phone_verification.admin.notifications.check_failed', ['reason' => __('phone_verification.failure_kinds.auth')]));
});

it('«Отправить тест на мой номер» sends one call under the limits and writes the journal', function (): void {
    enablePhoneVerification();
    $gateway = phoneStub();

    $component = Livewire::test(ManagePhoneVerificationSettings::class)
        ->callAction(TestAction::make('sendTest')->schemaComponent('connectionActions', schema: 'form'), ['phone' => '+7 999 123-45-67'])
        ->assertNotified(__('phone_verification.admin.notifications.test_sent'));

    expect($gateway->types())->toBe(['flash'])
        ->and(AdminAuditEntry::query()->where('action', 'phone_verification.test')->count())->toBe(1);

    // The 60-second pause of the number holds for the test as well.
    $component->callAction(TestAction::make('sendTest')->schemaComponent('connectionActions', schema: 'form'), ['phone' => '+7 999 123-45-67']);

    expect($gateway->types())->toBe(['flash'])
        ->and(AdminAuditEntry::query()->where('action', 'phone_verification.test')->count())->toBe(2)
        ->and(PhoneVerification::query()->count())->toBe(1);
});
