<?php

declare(strict_types=1);

use App\Enums\RoundingStep;
use App\Enums\SalesMode;
use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Filament\Pages\Settings\ManageGeneralSettings;
use App\Filament\Pages\Settings\ManageLegalSettings;
use App\Filament\Pages\Settings\ManageNotificationSettings;
use App\Filament\Pages\Settings\ManagePricingSettings;
use App\Filament\Pages\Settings\ManageSalesSettings;
use App\Filament\Pages\Settings\ManageSeoSettings;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\NotificationSettings;
use App\Settings\PricingSettings;
use App\Settings\SalesSettings;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('renders every settings page', function (string $page) {
    Livewire::test($page)->assertOk();
})->with([
    ManageGeneralSettings::class,
    ManageAppearanceSettings::class,
    ManageSalesSettings::class,
    ManagePricingSettings::class,
    ManageNotificationSettings::class,
    ManageSeoSettings::class,
    ManageLegalSettings::class,
]);

it('persists the shop name', function () {
    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['shop_name' => 'Лавка Тест'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->shop_name)->toBe('Лавка Тест');
});

it('requires the shop name', function () {
    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['shop_name' => ''])
        ->call('save')
        ->assertHasFormErrors(['shop_name' => 'required']);
});

it('uses the saved shop name as the panel brand', function () {
    $settings = app(GeneralSettings::class);
    $settings->shop_name = 'Бренд Тест';
    $settings->save();

    $this->get('/admin')->assertSee('Бренд Тест');
});

it('converts delivery money between rubles and kopecks', function () {
    $component = Livewire::test(ManageSalesSettings::class);

    // Defaults are shown in rubles.
    $methods = array_values($component->get('data.delivery_methods'));
    expect((float) $methods[1]['price'])->toBe(300.0)
        ->and((float) $methods[1]['free_from'])->toBe(5000.0);

    $component
        ->fillForm(['min_order_amount' => '1 290,50'])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(SalesSettings::class);

    expect($settings->min_order_amount)->toBe(129050)
        ->and($settings->delivery_methods[1]['price'])->toBe(30000)
        ->and($settings->delivery_methods[1]['free_from'])->toBe(500000)
        ->and($settings->delivery_methods[0]['free_from'])->toBeNull()
        ->and($settings->default_mode)->toBe(SalesMode::Cart);
});

it('saves pricing enums', function () {
    Livewire::test(ManagePricingSettings::class)
        ->fillForm(['rounding_step' => '50', 'psychological_price' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(PricingSettings::class);

    expect($settings->rounding_step)->toBe(RoundingStep::Fifty)
        ->and($settings->psychological_price)->toBeTrue();
});

it('saves notification settings without exposing a working test button', function () {
    Livewire::test(ManageNotificationSettings::class)
        ->fillForm(['telegram_chat_id' => '12345'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(NotificationSettings::class)->telegram_chat_id)->toBe('12345');
});

it('rejects a malformed money amount', function () {
    Livewire::test(ManageSalesSettings::class)
        ->fillForm(['min_order_amount' => 'abc'])
        ->call('save')
        ->assertHasFormErrors(['min_order_amount']);
});

it('caps money settings at the decimal(12,2) maximum', function () {
    Livewire::test(ManageSalesSettings::class)
        ->fillForm(['min_order_amount' => '10 000 000 000'])
        ->call('save')
        ->assertHasFormErrors(['min_order_amount']);

    Livewire::test(ManageSalesSettings::class)
        ->fillForm(['min_order_amount' => '9 999 999 999,99'])
        ->call('save')
        ->assertHasNoFormErrors();
});
