<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Enums\PhoneProviderFailureKind;
use App\Enums\PhoneVerificationDriver;
use App\Enums\PhoneVerificationError;
use App\Enums\PhoneVerificationPurpose;
use App\Exceptions\PhoneVerificationException;
use App\Jobs\SendPhoneVerificationAlert;
use App\Models\AdminAuditEntry;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PhoneVerification;
use App\Models\User;
use App\Services\Customers\CustomerAnonymizer;
use App\Services\PhoneVerification\GatewayFactory;
use App\Services\PhoneVerification\LogGateway;
use App\Services\PhoneVerification\PhoneVerificationDoctor;
use App\Services\PhoneVerification\PhoneVerificationService;
use App\Services\PhoneVerification\SmsAeroGateway;
use App\Services\PhoneVerification\SmsTemplate;
use App\Services\System\Cleanup;
use App\Services\Users\PermissionRegistry;
use App\Settings\NotificationSettings;
use App\Settings\PhoneVerificationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * PV-01, PV-02, PV-04, PV-08, PV-09: settings, drivers, template, doctor, balance check, cleanup, anonymization.
 */
function pvProduction(): void
{
    app()->detectEnvironment(fn (): string => 'production');
}

describe('settings (PV-01)', function (): void {
    it('has the defaults of the TZ and no secrets', function (): void {
        $settings = app(PhoneVerificationSettings::class);

        expect($settings->enabled)->toBeFalse()
            ->and($settings->driver)->toBe('sms_aero')
            ->and($settings->mode)->toBe('flash_call')
            ->and($settings->sms_template)->toBe('Код подтверждения: {code}. {shop}')
            ->and($settings->registration)->toBe('off')
            ->and($settings->profile_change)->toBeFalse()
            ->and($settings->wholesale)->toBeFalse()
            ->and($settings->guest_checkout)->toBe('off')
            ->and($settings->guest_checkout_min_amount)->toBeInt()
            ->and($settings->allowed_prefixes)->toBe(['+79'])
            ->and($settings->daily_limit)->toBe(200)
            ->and($settings->on_daily_limit)->toBe('block')
            ->and($settings->on_provider_failure)->toBe('skip')
            ->and($settings->code_ttl_minutes)->toBe(5)
            ->and($settings->provider_login)->toBeNull()
            ->and($settings->provider_api_key)->toBeNull();
    });

    it('stores the login and the key encrypted', function (): void {
        enablePhoneVerification();

        $raw = DB::table('settings')->where('group', 'phone_verification')->whereIn('name', ['provider_login', 'provider_api_key'])->pluck('payload')->implode('|');

        expect($raw)->not->toContain('TEST-api-key-123')->not->toContain('owner@example.com');
        app()->forgetInstance(PhoneVerificationSettings::class);
        expect(app(PhoneVerificationSettings::class)->provider_api_key)->toBe('TEST-api-key-123');
    });

    it('has a reversible settings migration', function (): void {
        $migration = require database_path('settings/2026_11_25_000600_create_phone_verification_settings.php');

        $migration->down();
        expect(DB::table('settings')->where('group', 'phone_verification')->count())->toBe(0);

        $migration->up();
        app()->forgetInstance(PhoneVerificationSettings::class);
        expect(app(PhoneVerificationSettings::class)->daily_limit)->toBe(200);
    });

    it('has settings.phone_verification.view and .update, and the Manager has neither by default', function (): void {
        expect(PermissionRegistry::exists('settings.phone_verification.view'))->toBeTrue()
            ->and(PermissionRegistry::exists('settings.phone_verification.update'))->toBeTrue()
            ->and(PermissionRegistry::managerDefaults())->not->toContain('settings.phone_verification.view', 'settings.phone_verification.update');
    });
});

describe('SMS text (PV-04)', function (): void {
    it('renders the default text', function (): void {
        expect(SmsTemplate::render(SmsTemplate::DEFAULT, '1234', 'Alev Shop'))->toBe('Код подтверждения: 1234. Alev Shop');
    });

    it('shortens the shop name so the text fits one SMS', function (): void {
        $text = SmsTemplate::render(SmsTemplate::DEFAULT, '1234', str_repeat('Очень длинное название магазина ', 5));

        expect(mb_strlen($text))->toBeLessThanOrEqual(70)->and($text)->toStartWith('Код подтверждения: 1234. Очень длинное');
    });

    it('validates the template', function (): void {
        expect(SmsTemplate::validate(SmsTemplate::DEFAULT))->toBeNull()
            ->and(SmsTemplate::validate('Ваш код {code} — {shop}'))->toBeNull()
            ->and(SmsTemplate::validate('Код подтверждения без цифр'))->toBe('no_code')
            ->and(SmsTemplate::validate('Код {code}, подробнее https://example.ru/c'))->toBe('link')
            ->and(SmsTemplate::validate('Код {code}, сайт example.ru'))->toBe('link')
            ->and(SmsTemplate::validate('Код {code}. '.str_repeat('а', 80)))->toBe('too_long');
    });
});

describe('drivers (PV-02)', function (): void {
    it('builds the SMS Aero gateway from the settings', function (): void {
        expect(GatewayFactory::forSettings(enablePhoneVerification()))->toBeInstanceOf(SmsAeroGateway::class);
    });

    it('builds the log gateway outside production and logs the masked number with the code', function (): void {
        $settings = enablePhoneVerification(['driver' => 'log']);
        $gateway = GatewayFactory::forSettings($settings);
        Log::spy();

        $result = $gateway->startFlashCall('+79991234567', '4321');

        expect($gateway)->toBeInstanceOf(LogGateway::class)->and($result->code)->toBe('4321');
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []): bool => ($context['phone'] ?? null) === '+7******4567' && ($context['code'] ?? null) === '4321')->once();
    });

    it('cannot select the log driver in production', function (): void {
        $settings = enablePhoneVerification(['driver' => 'log']);
        pvProduction();

        expect(fn () => GatewayFactory::forSettings($settings))->toThrow(RuntimeException::class)
            ->and(PhoneVerificationDriver::options())->toHaveKey('sms_aero')->not->toHaveKey('log')
            ->and(app(PhoneVerificationService::class)->isEnabled())->toBeFalse();

        try {
            app(PhoneVerificationService::class)->start('+79991234567', PhoneVerificationPurpose::GuestCheckout, phoneSession(), '192.0.2.1');
            $this->fail('start() must refuse');
        } catch (PhoneVerificationException $e) {
            expect($e->reason)->toBe(PhoneVerificationError::Disabled);
        }
    });

    it('offers the log driver outside production', function (): void {
        expect(PhoneVerificationDriver::options())->toHaveKeys(['sms_aero', 'log']);
    });
});

describe('shop:doctor and the «Система» page (PV-09)', function (): void {
    it('reports an error for the log driver in production', function (): void {
        enablePhoneVerification(['driver' => 'log']);
        pvProduction();

        $check = app(PhoneVerificationDoctor::class)->check();

        expect($check->status)->toBe(CheckStatus::Error)->and($check->hint)->not->toBeNull();
    });

    it('is ok while the feature is off', function (): void {
        expect(app(PhoneVerificationDoctor::class)->check()->status)->toBe(CheckStatus::Ok);
    });

    it('reports missing credentials, the last balance and a low balance', function (): void {
        enablePhoneVerification(['provider_login' => null, 'provider_api_key' => null]);
        expect(app(PhoneVerificationDoctor::class)->check()->status)->toBe(CheckStatus::Error);

        enablePhoneVerification(['balance_threshold' => 20000]);
        expect(app(PhoneVerificationDoctor::class)->check()->value)->toContain('ещё не проверялся');

        enablePhoneVerification(['last_balance' => '312.50', 'last_balance_at' => now()->toIso8601String()]);
        $ok = app(PhoneVerificationDoctor::class)->check();
        expect($ok->status)->toBe(CheckStatus::Ok)->and($ok->value)->toContain('312.50');

        enablePhoneVerification(['last_balance' => '150.00']);
        expect(app(PhoneVerificationDoctor::class)->check()->status)->toBe(CheckStatus::Warning);
    });

    it('is listed by shop:doctor', function (): void {
        User::factory()->admin()->create();
        Artisan::call('shop:doctor', ['--json' => true]);

        expect(array_column(json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR), 'key'))->toContain('phone_verification');
    });
});

describe('balance check (PV-09)', function (): void {
    beforeEach(function (): void {
        Queue::fake();
        $notifications = app(NotificationSettings::class);
        $notifications->notify_emails = ['owner@example.com'];
        $notifications->notify_shop_email = true;
        $notifications->save();
        $this->gateway = phoneStub();
    });

    it('warns the shop below the threshold and remembers the balance', function (): void {
        enablePhoneVerification(['balance_threshold' => 20000]);
        $this->gateway->balance = '150.00';

        $this->artisan('shop:phone-verification-balance')->assertSuccessful();

        Queue::assertPushed(SendPhoneVerificationAlert::class, fn (SendPhoneVerificationAlert $job): bool => $job->topic === 'low_balance'
            && $job->channel === 'mail' && $job->params === ['balance' => '150.00', 'threshold' => '200.00']);
        expect(app(PhoneVerificationSettings::class)->last_balance)->toBe('150.00');
    });

    it('does not warn with enough money', function (): void {
        enablePhoneVerification(['balance_threshold' => 20000]);
        $this->gateway->balance = '200.00';

        $this->artisan('shop:phone-verification-balance')->assertSuccessful();

        Queue::assertNothingPushed();
    });

    it('does nothing while switched off', function (): void {
        $this->gateway->balance = '1.00';

        $this->artisan('shop:phone-verification-balance')->assertSuccessful();

        Queue::assertNothingPushed();
        expect(app(PhoneVerificationSettings::class)->last_balance)->toBeNull();
    });

    it('records a failed check and warns about the failure', function (): void {
        enablePhoneVerification();
        $this->gateway->failWith(PhoneProviderFailureKind::Auth);

        $this->artisan('shop:phone-verification-balance')->assertSuccessful();

        expect(app(PhoneVerificationSettings::class)->last_balance_error)->toBe('auth');
        Queue::assertPushed(SendPhoneVerificationAlert::class, fn (SendPhoneVerificationAlert $job): bool => $job->topic === 'provider_failure');
    });

    it('renders the alert texts for mail and Telegram without a phone number', function (): void {
        foreach (['provider_failure' => ['kind' => 'auth'], 'low_balance' => ['balance' => '1.00', 'threshold' => '200.00'], 'empty_balance' => [], 'daily_limit' => ['limit' => '200']] as $topic => $params) {
            expect(__('phone_verification.alerts.'.$topic.'.subject'))->not->toContain('phone_verification.')
                ->and(__('phone_verification.alerts.'.$topic.'.body', $params))->not->toContain('phone_verification.')->not->toMatch('/:[a-z_]+/');
        }
    });
});

describe('test button (PV-01)', function (): void {
    it('uses the limits and the daily limit and writes the journal without the number', function (): void {
        $gateway = phoneStub();
        enablePhoneVerification();
        $admin = User::factory()->admin()->create();
        $pv = app(PhoneVerificationService::class);
        $session = phoneSession();

        $pv->sendTest($admin, '+79991234567', $session, '192.0.2.1');

        expect($gateway->types())->toBe(['flash'])
            ->and(PhoneVerification::query()->firstOrFail()->purpose)->toBe(PhoneVerificationPurpose::Test);

        // The pause of the number applies to the test as well, and the refusal is journaled.
        expect(fn () => $pv->sendTest($admin, '+79991234567', $session, '192.0.2.1'))->toThrow(PhoneVerificationException::class);

        $entries = AdminAuditEntry::query()->where('action', 'phone_verification.test')->orderBy('id')->get();
        expect($entries)->toHaveCount(2)
            ->and($entries[0]->data)->toEqualCanonicalizing(['result' => 'sent', 'method' => 'flash_call'])
            ->and($entries[1]->data)->toBe(['result' => 'throttled'])
            ->and($entries[0]->user_id)->toBe($admin->id)
            ->and(json_encode($entries->toArray()))->not->toContain('9991234567');

        enablePhoneVerification(['daily_limit' => 0]);
        expect(fn () => $pv->sendTest($admin, '+79990000000', $session, '192.0.2.1'))->toThrow(PhoneVerificationException::class);
    });
});

describe('cleanup and anonymization (PV-08)', function (): void {
    it('deletes records older than 90 days in shop:cleanup', function (): void {
        $old = PhoneVerification::factory()->create(['created_at' => now()->subDays(91)]);
        $fresh = PhoneVerification::factory()->create();

        $summary = app(Cleanup::class)->run(microtime(true) + 30);
        $this->artisan('shop:cleanup')->assertSuccessful();

        expect($summary['phone_verifications'])->toBe(1)
            ->and(PhoneVerification::query()->whereKey($old->id)->exists())->toBeFalse()
            ->and(PhoneVerification::query()->whereKey($fresh->id)->exists())->toBeTrue();
    });

    it('deletes the records of an anonymized customer and clears phone_verified_at', function (): void {
        $customer = Customer::factory()->create(['phone' => '+79991234567']);
        $customer->forceFill(['phone_verified_at' => now()])->save();
        $other = Customer::factory()->create(['phone' => '+79990000000']);
        $byId = PhoneVerification::factory()->create(['customer_id' => $customer->id, 'phone' => '+79995550000']);
        $byPhone = PhoneVerification::factory()->create(['phone' => '+79991234567']);
        $unrelated = PhoneVerification::factory()->create(['customer_id' => $other->id, 'phone' => '+79990000000']);

        app(CustomerAnonymizer::class)->anonymize($customer);

        expect(PhoneVerification::query()->whereKey([$byId->id, $byPhone->id])->count())->toBe(0)
            ->and(PhoneVerification::query()->whereKey($unrelated->id)->exists())->toBeTrue()
            ->and($customer->refresh()->phone_verified_at)->toBeNull();
    });

    it('has the data columns of PV-08', function (): void {
        $customer = Customer::factory()->create();
        $order = Order::factory()->create();

        expect($customer->refresh()->phone_verified_at)->toBeNull()
            ->and($order->refresh()->phone_verified)->toBeFalse();

        $order->forceFill(['phone_verified' => true])->save();
        expect($order->refresh()->phone_verified)->toBeTrue();
    });

    it('has reversible migrations', function (): void {
        $migration = require database_path('migrations/2026_11_25_100001_create_phone_verifications_table.php');

        $migration->down();
        expect(Schema::hasTable('phone_verifications'))->toBeFalse()
            ->and(Schema::hasColumn('customers', 'phone_verified_at'))->toBeFalse()
            ->and(Schema::hasColumn('orders', 'phone_verified'))->toBeFalse();

        $migration->up();
        expect(Schema::hasTable('phone_verifications'))->toBeTrue()
            ->and(Schema::hasColumn('customers', 'phone_verified_at'))->toBeTrue()
            ->and(Schema::hasColumn('orders', 'phone_verified'))->toBeTrue();
    });
});
