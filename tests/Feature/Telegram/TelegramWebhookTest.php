<?php

declare(strict_types=1);

use App\Models\TelegramAccount;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateHandler;
use App\Settings\GeneralSettings;
use App\Settings\TelegramSettings;
use Illuminate\Support\Facades\Log;

/**
 * TG-04: the webhook route, its secret, size and JSON checks, idempotency and always-200 behaviour.
 */
beforeEach(function (): void {
    connectTelegramBot();
});

it('rejects a wrong or missing secret with an empty 403 and logs without content (TG-04)', function (): void {
    Log::spy();

    telegramWebhook(tgMessage(1, '/start'), ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'wrong'])
        ->assertForbidden()
        ->assertContent('');

    test()->call('POST', '/telegram/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(tgMessage(2, '/start')))
        ->assertForbidden();

    expect(TelegramUpdate::query()->count())->toBe(0)->and(TelegramAccount::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('Telegram webhook rejected: bad secret token', Mockery::on(fn (array $context): bool => ! str_contains(json_encode($context), '/start')));
});

it('rejects everything while no secret is configured', function (): void {
    $settings = app(TelegramSettings::class);
    $settings->webhook_secret = null;
    $settings->save();

    test()->call('POST', '/telegram/webhook', [], [], [], ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => ''], json_encode(tgMessage(3, '/start')))->assertForbidden();
});

it('answers 400 for a body above 1 MB or one that is not JSON (TG-04)', function (): void {
    $big = tgMessage(10, str_repeat('a', 1_100_000));

    telegramWebhook($big)->assertStatus(400);

    test()->call('POST', '/telegram/webhook', [], [], [], ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => TG_SECRET], 'not json')->assertStatus(400);
    test()->call('POST', '/telegram/webhook', [], [], [], ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => TG_SECRET], json_encode(['no' => 'update_id']))->assertStatus(400);

    expect(TelegramUpdate::query()->count())->toBe(0);
});

it('records the update id and processes a redelivery as a no-op (TG-04)', function (): void {
    telegramWebhook(tgMessage(20, '/start'))->assertOk()->assertJsonPath('method', 'sendMessage');

    expect(TelegramUpdate::query()->where('update_id', 20)->value('type'))->toBe('message');
    $account = TelegramAccount::query()->firstOrFail();
    $account->forceFill(['notify_enabled' => false])->save();

    // Same update_id again: 200 with no body and no processing (notify_enabled stays off, no reply).
    telegramWebhook(tgMessage(20, '/start'))->assertOk()->assertContent('');

    expect(TelegramUpdate::query()->count())->toBe(1)->and($account->refresh()->notify_enabled)->toBeFalse();
});

it('stores no body of the update and starts no session (TG-04, NF-15)', function (): void {
    $before = glob(storage_path('framework/sessions/*')) ?: [];
    $response = telegramWebhook(tgMessage(30, '/start'));

    expect($response->headers->getCookies())->toBe([])
        ->and(json_encode(TelegramUpdate::query()->get()->toArray()))->not->toContain('ivan_tg')
        ->and(glob(storage_path('framework/sessions/*')) ?: [])->toBe($before);
});

it('answers 200 after recording even when processing fails, without personal data in the log (TG-04)', function (): void {
    Log::spy();
    $this->mock(TelegramUpdateHandler::class, function ($mock): void {
        $mock->shouldReceive('handle')->andThrow(new RuntimeException('boom ivan@example.com'));
    });

    telegramWebhook(tgMessage(40, '/start'))->assertOk()->assertContent('');

    expect(TelegramUpdate::query()->where('update_id', 40)->exists())->toBeTrue();
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => ! str_contains(json_encode($context), 'ivan@example.com') && $context['exception'] === RuntimeException::class);
});

it('is not subject to the closed-shop mode (TG-04)', function (): void {
    $sales = app(GeneralSettings::class);
    $sales->shop_open = false;
    $sales->save();

    telegramWebhook(tgMessage(50, '/help'))->assertOk()->assertJsonPath('method', 'sendMessage');
});

it('limits the webhook to 120 requests per minute per IP (TG-04)', function (): void {
    for ($i = 0; $i < 120; $i++) {
        telegramWebhook(['update_id' => 1000 + $i, 'edited_message' => []])->assertOk();
    }

    telegramWebhook(['update_id' => 2000, 'edited_message' => []])->assertStatus(429);
});
