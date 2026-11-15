<?php

declare(strict_types=1);

use App\Enums\PhoneProviderFailureKind;
use App\Exceptions\PhoneProviderFailure;
use App\Exceptions\PhoneProviderRefusal;
use App\Services\PhoneVerification\SmsAeroGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * PV-02, PV-05, PV-11: parsing of the SMS Aero answers on the saved samples (tests/Fixtures/sms_aero), failure
 * classification, credentials never in messages. No real request is made (Http::fake).
 */
const SA_LOGIN = 'owner@example.com';
const SA_KEY = 'SECRET-key-987';

function smsAeroFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/sms_aero/'.$name.'.json'));
}

/** A fresh fake: a second Http::fake() on the same factory would keep answering with the first stub. */
function smsAeroFake(mixed $response): void
{
    Http::swap(new Factory);
    Http::fake(['gate.smsaero.ru/*' => $response]);
}

function smsAero(string $sender = 'AlevShop'): SmsAeroGateway
{
    return new SmsAeroGateway(SA_LOGIN, SA_KEY, $sender);
}

/** Runs the callback and returns the exception it throws. */
function smsAeroError(Closure $call): Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Nothing was thrown');
}

it('sends a flash call with the client code and parses the answer', function (): void {
    smsAeroFake(Http::response(smsAeroFixture('flashcall_send_ok'), 200));

    $result = smsAero()->startFlashCall('+79991234567', '4821');

    expect($result->requestId)->toBe('4521877')->and($result->code)->toBe('4821')->and($result->cost)->toBe('1.3000');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://gate.smsaero.ru/v2/flashcall/send'
        && $request['phone'] === '79991234567'
        && $request['code'] === '4821'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode(SA_LOGIN.':'.SA_KEY)));
    Http::assertSentCount(1);
});

it('sends an SMS and parses the answer', function (): void {
    smsAeroFake(Http::response(smsAeroFixture('sms_send_ok'), 200));

    $result = smsAero()->sendSms('+79991234567', 'Код подтверждения: 4821. Alev');

    expect($result->requestId)->toBe('98877661')->and($result->cost)->toBe('5.9500');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gate.smsaero.ru/v2/sms/send'
        && $request['number'] === '79991234567'
        && $request['text'] === 'Код подтверждения: 4821. Alev'
        && $request['sign'] === 'AlevShop');
});

it('leaves the sender out when none is set', function (): void {
    smsAeroFake(Http::response(smsAeroFixture('sms_send_ok'), 200));

    smsAero('')->sendSms('+79991234567', 'text 1234');

    Http::assertSent(fn (Request $request): bool => ! isset($request['sign']));
});

it('reads the balance', function (): void {
    smsAeroFake(Http::response(smsAeroFixture('balance_ok'), 200));

    expect(smsAero()->balance())->toBe('312.50');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://gate.smsaero.ru/v2/balance');
});

it('uses the client code of the caller', function (): void {
    expect(smsAero()->supportsCallerCode())->toBeTrue();
});

it('keeps the connect and answer timeouts of PV-02', function (): void {
    expect(SmsAeroGateway::CONNECT_TIMEOUT)->toBeLessThanOrEqual(3)->and(SmsAeroGateway::TIMEOUT)->toBeLessThanOrEqual(5);
});

describe('failures (PV-05)', function (): void {
    it('classifies HTTP 5xx as a failure and does not retry', function (): void {
        smsAeroFake(Http::response(smsAeroFixture('error_server'), 503));

        $e = smsAeroError(fn () => smsAero()->startFlashCall('+79991234567', '1234'));

        expect($e)->toBeInstanceOf(PhoneProviderFailure::class)->and($e->kind)->toBe(PhoneProviderFailureKind::Server);
        Http::assertSentCount(1);
    });

    it('classifies HTTP 401 and an authorization message as an authorization failure', function (): void {
        smsAeroFake(Http::response(smsAeroFixture('error_auth'), 401));
        $e = smsAeroError(fn () => smsAero()->balance());
        expect($e)->toBeInstanceOf(PhoneProviderFailure::class)->and($e->kind)->toBe(PhoneProviderFailureKind::Auth);

        smsAeroFake(Http::response(smsAeroFixture('error_auth'), 200));
        $e = smsAeroError(fn () => smsAero()->sendSms('+79991234567', 'text'));
        expect($e)->toBeInstanceOf(PhoneProviderFailure::class)->and($e->kind)->toBe(PhoneProviderFailureKind::Auth);
    });

    it('classifies a missing login or key as an authorization failure without a request', function (): void {
        Http::fake();

        $e = smsAeroError(fn () => (new SmsAeroGateway('', ''))->balance());

        expect($e)->toBeInstanceOf(PhoneProviderFailure::class)->and($e->kind)->toBe(PhoneProviderFailureKind::Auth);
        Http::assertNothingSent();
    });

    it('classifies a timeout and a network error', function (): void {
        smsAeroFake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'));
        $e = smsAeroError(fn () => smsAero()->balance());
        expect($e)->toBeInstanceOf(PhoneProviderFailure::class)->and($e->kind)->toBe(PhoneProviderFailureKind::Timeout);

        smsAeroFake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: gate.smsaero.ru'));
        $e = smsAeroError(fn () => smsAero()->balance());
        expect($e)->toBeInstanceOf(PhoneProviderFailure::class)->and($e->kind)->toBe(PhoneProviderFailureKind::Network);
    });

    it('cuts the login and the key out of error texts', function (): void {
        smsAeroFake(fn () => throw new ConnectionException('cURL error 7: failed to connect (auth '.SA_LOGIN.':'.SA_KEY.' '.base64_encode(SA_LOGIN.':'.SA_KEY).')'));

        $e = smsAeroError(fn () => smsAero()->balance());

        expect($e->getMessage())->not->toContain(SA_KEY)->not->toContain(SA_LOGIN)->not->toContain(base64_encode(SA_LOGIN.':'.SA_KEY))->toContain('***');
    });

    it('treats an empty balance as a refusal, not as a failure', function (): void {
        smsAeroFake(Http::response(smsAeroFixture('error_insufficient'), 402));
        $e = smsAeroError(fn () => smsAero()->startFlashCall('+79991234567', '1234'));
        expect($e)->toBeInstanceOf(PhoneProviderRefusal::class)->and($e->insufficientBalance)->toBeTrue();

        // The same answer with HTTP 200.
        smsAeroFake(Http::response(smsAeroFixture('error_insufficient'), 200));
        $e = smsAeroError(fn () => smsAero()->sendSms('+79991234567', 'text'));
        expect($e)->toBeInstanceOf(PhoneProviderRefusal::class)->and($e->insufficientBalance)->toBeTrue();
    });

    it('treats a refused number as a refusal, not as a failure', function (): void {
        smsAeroFake(Http::response(smsAeroFixture('error_validation'), 400));

        $e = smsAeroError(fn () => smsAero()->startFlashCall('+79991234567', '1234'));

        expect($e)->toBeInstanceOf(PhoneProviderRefusal::class)->and($e->insufficientBalance)->toBeFalse();
    });

    it('treats an answer without the expected data as a refusal', function (): void {
        smsAeroFake(Http::response(['success' => true, 'data' => ['status' => 0]], 200));
        expect(smsAeroError(fn () => smsAero()->sendSms('+79991234567', 'text')))->toBeInstanceOf(PhoneProviderRefusal::class);

        smsAeroFake(Http::response('<html>oops</html>', 200));
        expect(smsAeroError(fn () => smsAero()->balance()))->toBeInstanceOf(PhoneProviderRefusal::class);

        smsAeroFake(Http::response(['success' => true, 'data' => ['id' => 1, 'code' => 'abc']], 200));
        expect(smsAeroError(fn () => smsAero()->startFlashCall('+79991234567', '1234')))->toBeInstanceOf(PhoneProviderRefusal::class);
    });
});

it('treats a zero balance as a refusal even when the text says «отключена» (PV-05)', function (int $status, string $message): void {
    smsAeroFake(Http::response(['success' => false, 'message' => $message], $status));

    $e = smsAeroError(fn () => smsAero()->sendSms('+79991234567', 'text'));

    expect($e)->toBeInstanceOf(PhoneProviderRefusal::class)->and($e->insufficientBalance)->toBeTrue();
})->with([
    [402, 'Недостаточно средств, услуга отключена'],
    [200, 'Недостаточно средств, услуга отключена'],
    [402, 'Ошибка подключения к оператору'],
    [200, 'Пополните баланс или включите автоплатёж'],
]);

it('treats a refused number as a refusal, never as a provider failure (PV-05)', function (string $message): void {
    smsAeroFake(Http::response(['success' => false, 'message' => $message], 200));

    $e = smsAeroError(fn () => smsAero()->sendSms('+79991234567', 'text'));

    expect($e)->toBeInstanceOf(PhoneProviderRefusal::class)->and($e->insufficientBalance)->toBeFalse();
})->with(['Номер отключен', 'Ошибка подключения к оператору', 'Исключение для номера']);

it('still classifies a real authorization message as an auth failure', function (string $message): void {
    smsAeroFake(Http::response(['success' => false, 'message' => $message], 200));

    $e = smsAeroError(fn () => smsAero()->sendSms('+79991234567', 'text'));

    expect($e)->toBeInstanceOf(PhoneProviderFailure::class)->and($e->kind)->toBe(PhoneProviderFailureKind::Auth);
})->with(['Неверный API ключ', 'Invalid api key', 'Ошибка авторизации', 'Неверный ключ']);

it('lets an unexpected error bubble up instead of calling it a network failure', function (): void {
    smsAeroFake(fn () => throw new TypeError('bug'));

    expect(fn () => smsAero()->sendSms('+79991234567', 'text'))->toThrow(TypeError::class);
});

it('masks phone-like digit runs in provider messages (PV-10, NF-15)', function (): void {
    smsAeroFake(Http::response(['success' => false, 'message' => 'Номер 79001234567 не поддерживается'], 200));

    $e = smsAeroError(fn () => smsAero()->sendSms('+79001234567', 'text'));

    expect($e->getMessage())->not->toContain('79001234567')->and($e->getMessage())->toContain('4567');
});
