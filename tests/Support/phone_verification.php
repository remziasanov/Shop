<?php

declare(strict_types=1);

use App\Enums\PhoneProviderFailureKind;
use App\Exceptions\PhoneProviderFailure;
use App\Exceptions\PhoneProviderRefusal;
use App\Services\PhoneVerification\FlashCallResult;
use App\Services\PhoneVerification\PhoneVerificationGateway;
use App\Services\PhoneVerification\SmsResult;
use App\Settings\PhoneVerificationSettings;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

/**
 * Provider stub of the PV tests (PV-11). With `$callerCode = true` it behaves like SMS Aero (the client's code is
 * used), with `false` like callcheck of SMS.ru (the argument is ignored, `$providerCode` is returned). `$throw` makes the
 * next calls fail; no real request is ever made.
 */
final class StubPhoneGateway implements PhoneVerificationGateway
{
    /** @var list<array{type: string, phone: string, arg: ?string}> */
    public array $calls = [];

    public ?Throwable $throw = null;

    public string $providerCode = '7351';

    public ?string $balance = '500.00';

    public ?string $lastCode = null;

    public function __construct(public bool $callerCode = true) {}

    public function startFlashCall(string $phone, ?string $code): FlashCallResult
    {
        $this->calls[] = ['type' => 'flash', 'phone' => $phone, 'arg' => $code];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->lastCode = $this->callerCode ? (string) $code : $this->providerCode;

        return new FlashCallResult('req-'.count($this->calls), $this->lastCode, '1.3000');
    }

    public function sendSms(string $phone, string $text): SmsResult
    {
        $this->calls[] = ['type' => 'sms', 'phone' => $phone, 'arg' => $text];

        if ($this->throw !== null) {
            throw $this->throw;
        }

        preg_match('/\b(\d{4})\b/', $text, $match);
        $this->lastCode = $match[1] ?? null;

        return new SmsResult('req-'.count($this->calls), '5.9500');
    }

    public function balance(): ?string
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->balance;
    }

    public function supportsCallerCode(): bool
    {
        return $this->callerCode;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_column($this->calls, 'type');
    }

    public function failWith(PhoneProviderFailureKind $kind): void
    {
        $this->throw = new PhoneProviderFailure($kind, 'stub '.$kind->value);
    }

    public function refuseWithBalance(): void
    {
        $this->throw = new PhoneProviderRefusal(true, 'stub zero balance');
    }
}

/**
 * Binds a provider stub into the container (the service resolves the gateway on every call).
 */
function phoneStub(bool $callerCode = true): StubPhoneGateway
{
    $stub = new StubPhoneGateway($callerCode);
    app()->instance(PhoneVerificationGateway::class, $stub);

    return $stub;
}

/**
 * Switches verification on with fake credentials; `$values` override settings.
 *
 * @param  array<string, mixed>  $values
 */
function enablePhoneVerification(array $values = []): PhoneVerificationSettings
{
    $settings = app(PhoneVerificationSettings::class);
    $settings->enabled = true;
    $settings->provider_login = 'owner@example.com';
    $settings->provider_api_key = 'TEST-api-key-123';

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

/**
 * A fresh browser session (the binding token of the code lives in it).
 */
function phoneSession(): Store
{
    return new Store('phone-test', new ArraySessionHandler(120));
}
