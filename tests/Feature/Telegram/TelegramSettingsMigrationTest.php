<?php

declare(strict_types=1);

use App\Settings\NotificationSettings;
use App\Settings\TelegramSettings;
use Illuminate\Support\Facades\DB;

/**
 * TG-01, NF-63: the settings migration moves the three bot fields from notifications to telegram with their stored
 * values (the token stays encrypted as is) and is reversible.
 */
function telegramSettingsMigration(): object
{
    return require database_path('settings/2026_11_22_000400_create_telegram_settings.php');
}

function rawSetting(string $group, string $name): ?string
{
    $value = DB::table('settings')->where('group', $group)->where('name', $name)->value('payload');

    return is_string($value) ? $value : null;
}

it('keeps the token, work chat and switch through down() and up() without decrypting', function (): void {
    $settings = app(TelegramSettings::class);
    $settings->enabled = true;
    $settings->bot_token = '123456:TEST-token_abc';
    $settings->work_chat_id = '-1001';
    $settings->save();

    $encrypted = rawSetting('telegram', 'bot_token');
    expect($encrypted)->not->toContain('TEST-token_abc');

    telegramSettingsMigration()->down();

    // Back in the notifications group, byte for byte.
    expect(rawSetting('notifications', 'telegram_bot_token'))->toBe($encrypted)
        ->and(rawSetting('notifications', 'telegram_chat_id'))->toBe('"-1001"')
        ->and(rawSetting('notifications', 'telegram_enabled'))->toBe('true')
        ->and(DB::table('settings')->where('group', 'telegram')->count())->toBe(0);

    telegramSettingsMigration()->up();
    app()->forgetInstance(TelegramSettings::class);

    $restored = app(TelegramSettings::class);
    expect($restored->bot_token)->toBe('123456:TEST-token_abc')
        ->and($restored->work_chat_id)->toBe('-1001')
        ->and($restored->enabled)->toBeTrue()
        ->and(rawSetting('telegram', 'bot_token'))->toBe($encrypted)
        ->and(DB::table('settings')->where('group', 'notifications')->whereIn('name', ['telegram_enabled', 'telegram_bot_token', 'telegram_chat_id'])->count())->toBe(0);
});

it('gives the new fields the TG-01 defaults and no secrets', function (): void {
    $settings = app(TelegramSettings::class);

    expect($settings->webhook_secret)->toBeNull()
        ->and($settings->bot_token)->toBeNull()
        ->and($settings->bot_id)->toBeNull()
        ->and($settings->menu_button_text)->toBe('Магазин')
        ->and($settings->initdata_ttl_customer)->toBe(3600)
        ->and($settings->initdata_ttl_staff)->toBe(3600)
        ->and($settings->miniapp_colors)->toBe('shop')
        ->and($settings->startapp_verified_at)->toBeNull()
        ->and($settings->work_chat_error)->toBeNull();
});

it('leaves the notification toggles in the notifications group', function (): void {
    $notifications = app(NotificationSettings::class);

    expect($notifications->notify_shop_telegram)->toBeTrue()
        ->and(property_exists($notifications, 'telegram_bot_token'))->toBeFalse();
});

it('stores the webhook secret encrypted', function (): void {
    $settings = app(TelegramSettings::class);
    $settings->webhook_secret = bin2hex(random_bytes(32));
    $settings->save();

    expect(rawSetting('telegram', 'webhook_secret'))->not->toContain($settings->webhook_secret);
});
