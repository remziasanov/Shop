<?php

declare(strict_types=1);

use App\Enums\RoundingDirection;
use App\Enums\RoundingScope;
use App\Enums\RoundingStep;
use App\Enums\SalesMode;
use App\Enums\StockDisplay;
use App\Settings\AppearanceSettings;
use App\Settings\GeneralSettings;
use App\Settings\HomePageSettings;
use App\Settings\LegalSettings;
use App\Settings\NotificationSettings;
use App\Settings\PricingSettings;
use App\Settings\SalesSettings;
use App\Settings\SeoSettings;
use App\Settings\TelegramSettings;

it('loads defaults of every settings group after migrations', function () {
    $general = app(GeneralSettings::class);
    expect($general->shop_name)->toBe('Магазин')
        ->and($general->shop_open)->toBeTrue()
        ->and($general->phones)->toBe([]);

    $appearance = app(AppearanceSettings::class);
    expect($appearance->grid_columns)->toBe(4)
        ->and($appearance->per_page)->toBe(24);

    $sales = app(SalesSettings::class);
    expect($sales->default_mode)->toBe(SalesMode::Cart)
        ->and($sales->stock_tracking)->toBeTrue()
        ->and($sales->allow_backorder)->toBeFalse()
        ->and($sales->stock_display)->toBe(StockDisplay::Presence)
        ->and($sales->reservation_hours)->toBe(48)
        ->and($sales->order_prefix)->toBe('Z')
        ->and($sales->reservation_prefix)->toBe('R')
        ->and($sales->inquiry_prefix)->toBe('Q')
        ->and($sales->delivery_methods)->toHaveCount(2)
        ->and($sales->delivery_methods[0]['name'])->toBe('Самовывоз')
        ->and($sales->delivery_methods[0]['requires_address'])->toBeFalse()
        ->and($sales->delivery_methods[1]['price'])->toBe(30000)
        ->and($sales->delivery_methods[1]['free_from'])->toBe(500000)
        ->and($sales->payment_methods)->toHaveCount(2);

    $pricing = app(PricingSettings::class);
    expect($pricing->rounding_step)->toBe(RoundingStep::None)
        ->and($pricing->rounding_direction)->toBe(RoundingDirection::Nearest)
        ->and($pricing->rounding_scope)->toBe(RoundingScope::Changed);

    $notifications = app(NotificationSettings::class);
    $telegram = app(TelegramSettings::class);
    expect($telegram->bot_token)->toBeNull()
        ->and($telegram->webhook_secret)->toBeNull()
        ->and($telegram->menu_button_text)->toBe('Магазин')
        ->and($telegram->initdata_ttl_customer)->toBe(3600)
        ->and($notifications->notify_emails)->toBe([]);

    expect(app(SeoSettings::class)->robots_txt)->toContain('User-agent: *')
        ->and(app(LegalSettings::class)->cookie_banner_enabled)->toBeTrue()
        ->and(app(HomePageSettings::class)->blocks)->toBe([]);
});

it('stores the telegram token encrypted', function () {
    $settings = app(TelegramSettings::class);
    $settings->bot_token = 'dummy-token';
    $settings->webhook_secret = 'dummy-secret';
    $settings->save();

    $token = DB::table('settings')->where('group', 'telegram')->where('name', 'bot_token')->value('payload');
    $secret = DB::table('settings')->where('group', 'telegram')->where('name', 'webhook_secret')->value('payload');

    expect($token)->not->toContain('dummy-token')
        ->and($secret)->not->toContain('dummy-secret')
        ->and(app(TelegramSettings::class)->bot_token)->toBe('dummy-token');
});
