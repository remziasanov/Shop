<?php

declare(strict_types=1);

use App\Jobs\SendTelegramMessage;
use App\Jobs\SendTelegramNotification;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramRateLimitedException;
use App\Services\Telegram\TelegramRateLimiter;
use App\Settings\TelegramSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * TG-10: the Bot API client, its error handling and the outgoing rate limits.
 */
beforeEach(function (): void {
    config(['shop.telegram_rate_limits' => true]);
    connectTelegramBot(['work_chat_id' => '-1001']);
    RateLimiter::clear('telegram:global');
});

it('times out after 5 seconds and calls the API with the saved token (TG-10)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['id' => 1]])]);

    $result = app(TelegramClient::class)->call('getMe');

    expect($result)->toBe(['id' => 1])->and(TelegramClient::TIMEOUT_SECONDS)->toBe(5);
    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/bot123456:TEST-token_abc/getMe');
});

it('strips the token from API and connection error texts (TG-10, NF-61)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized: bot123456:TEST-token_abc'], 401)]);

    expect(fn () => app(TelegramClient::class)->call('getMe'))
        ->toThrow(fn (TelegramApiException $e): bool => ! str_contains($e->getMessage(), 'TEST-token_abc') && $e->status === 401);

    Http::fake(fn () => throw new ConnectionException('cURL error 28: https://api.telegram.org/bot123456:TEST-token_abc/getMe timed out'));

    try {
        app(TelegramClient::class)->call('getMe');
    } catch (TelegramApiException $e) {
        expect($e->getMessage())->not->toContain('TEST-token_abc')->and($e->getPrevious())->toBeNull();
    }
});

it('turns HTTP 429 into a rate-limit exception carrying retry_after', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 17', 'parameters' => ['retry_after' => 17]], 429)]);

    try {
        app(TelegramClient::class)->sendMessage('555', 'hi');
        $this->fail('expected an exception');
    } catch (TelegramRateLimitedException $e) {
        expect($e->retryAfter)->toBe(17)->and($e->isRateLimited())->toBeTrue();
    }
});

it('releases the job for retry_after on 429 without failing it (TG-10)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'retry', 'parameters' => ['retry_after' => 17]], 429)]);
    $job = (new SendTelegramMessage('555', 'hello'))->withFakeQueueInteractions();

    $job->handle(app(TelegramClient::class));

    $job->assertReleased(17);
});

it('marks the account blocked and does not retry on 403 «bot was blocked» (TG-10)', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
    $account = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555]);
    $job = (new SendTelegramMessage('555', 'hello'))->withFakeQueueInteractions();

    $job->handle(app(TelegramClient::class));

    $job->assertNotReleased()->assertNotFailed();
    expect($account->refresh()->bot_blocked_at)->not->toBeNull();
});

it('marks the account blocked on 400 «chat not found»', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400)]);
    $account = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 555]);

    (new SendTelegramMessage('555', 'hello'))->handle(app(TelegramClient::class));

    expect($account->refresh()->bot_blocked_at)->not->toBeNull();
});

it('retries other errors: the job exception is thrown (NT-01), up to three exceptions', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 500, 'description' => 'Internal'], 500)]);
    $job = new SendTelegramMessage('555', 'hello');

    expect(fn () => $job->handle(app(TelegramClient::class)))->toThrow(TelegramApiException::class)
        ->and($job->maxExceptions)->toBe(3)
        ->and($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class);
});

it('keeps the token out of the queued job payload (NF-61)', function (): void {
    $payload = serialize(new SendTelegramMessage('555', 'hello', ['inline_keyboard' => []]));

    expect($payload)->not->toContain('TEST-token_abc')->not->toContain('123456:');
});

describe('work chat', function (): void {
    it('writes a 403 to work_chat_error and leaves telegram_accounts alone (TG-10)', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot is not a member of the supergroup chat'], 403)]);
        $account = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 1001]);
        $order = Order::factory()->create();
        $job = (new SendTelegramNotification($order->id, SendTelegramNotification::NEW_REQUEST))->withFakeQueueInteractions();

        $job->handle(app(TelegramNotifier::class));

        $job->assertNotReleased()->assertNotFailed();
        expect(app(TelegramSettings::class)->work_chat_error)->toContain('not a member')
            ->and($account->refresh()->bot_blocked_at)->toBeNull();
    });

    it('clears the error after a successful send', function (): void {
        $settings = app(TelegramSettings::class);
        $settings->work_chat_error = 'old';
        $settings->save();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        app(TelegramClient::class)->sendToWorkChat('<b>hi</b>');

        expect(app(TelegramSettings::class)->work_chat_error)->toBeNull();
    });

    it('replaces the chat id on migrate_to_chat_id and resends (TG-10)', function (): void {
        Http::fake(['api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -1002003004005]], 400)
            ->push(['ok' => true, 'result' => []])]);

        app(TelegramClient::class)->sendToWorkChat('<b>hi</b>');

        expect(app(TelegramSettings::class)->work_chat_id)->toBe('-1002003004005')->and(app(TelegramSettings::class)->work_chat_error)->toBeNull();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request['chat_id'] === '-1002003004005');
    });

    it('stores the chat id as a string and accepts @channel', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $settings = app(TelegramSettings::class);
        $settings->work_chat_id = '@my_channel';
        $settings->save();

        app(TelegramClient::class)->sendToWorkChat('hi');

        Http::assertSent(fn ($request): bool => $request['chat_id'] === '@my_channel');
        expect(app(TelegramSettings::class)->work_chat_id)->toBeString();
    });

    it('limits the work chat to 20 messages per minute (TG-10)', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $limiter = app(TelegramRateLimiter::class);

        for ($i = 0; $i < 20; $i++) {
            RateLimiter::clear('telegram:chat:'.md5('-1001'));
            expect($limiter->acquire('-1001', true))->toBe(0);
        }

        RateLimiter::clear('telegram:chat:'.md5('-1001'));

        expect($limiter->acquire('-1001', true))->toBeGreaterThan(0);
    });

    it('releases a notification job back to the queue when the work chat limit is hit', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $limiter = app(TelegramRateLimiter::class);

        for ($i = 0; $i < 20; $i++) {
            RateLimiter::clear('telegram:chat:'.md5('-1001'));
            $limiter->acquire('-1001', true);
        }

        RateLimiter::clear('telegram:chat:'.md5('-1001'));
        $order = Order::factory()->create();
        $job = (new SendTelegramNotification($order->id, SendTelegramNotification::NEW_REQUEST))->withFakeQueueInteractions();
        $job->handle(app(TelegramNotifier::class));

        $job->assertReleased();
        Http::assertNothingSent();
    });
});

describe('rate limiter', function (): void {
    it('allows one message per second to a chat and then asks to wait', function (): void {
        $limiter = app(TelegramRateLimiter::class);

        expect($limiter->acquire('555'))->toBe(0)->and($limiter->acquire('555'))->toBeGreaterThan(0)->and($limiter->acquire('556'))->toBe(0);
    });

    it('allows 25 messages per second in total', function (): void {
        $limiter = app(TelegramRateLimiter::class);

        for ($i = 0; $i < 25; $i++) {
            expect($limiter->acquire('chat-'.$i))->toBe(0);
        }

        expect($limiter->acquire('chat-new'))->toBeGreaterThan(0);
    });

    it('does not spend a slot when another bucket is full', function (): void {
        $limiter = app(TelegramRateLimiter::class);
        $limiter->acquire('555');
        $limiter->acquire('555'); // refused: must not hit the global bucket

        expect(RateLimiter::attempts('telegram:global'))->toBe(1);
    });

    it('releases a private message job back to the queue when the chat limit is hit', function (): void {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        app(TelegramRateLimiter::class)->acquire('555');
        $job = (new SendTelegramMessage('555', 'hello'))->withFakeQueueInteractions();

        $job->handle(app(TelegramClient::class));

        $job->assertReleased(1);
        Http::assertNothingSent();
    });
});
