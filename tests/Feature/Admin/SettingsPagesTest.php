<?php

declare(strict_types=1);

use App\Enums\RoundingStep;
use App\Enums\SalesMode;
use App\Filament\Pages\Settings\ManageAgeGateSettings;
use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Filament\Pages\Settings\ManageGeneralSettings;
use App\Filament\Pages\Settings\ManageLegalSettings;
use App\Filament\Pages\Settings\ManageNotificationSettings;
use App\Filament\Pages\Settings\ManagePricingSettings;
use App\Filament\Pages\Settings\ManageSalesSettings;
use App\Filament\Pages\Settings\ManageSeoSettings;
use App\Models\User;
use App\Settings\AppearanceSettings;
use App\Settings\GeneralSettings;
use App\Settings\NotificationSettings;
use App\Settings\PricingSettings;
use App\Settings\SalesSettings;
use App\Settings\SeoSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('renders every settings page', function (string $page) {
    Livewire::test($page)->assertOk();
})->with([
    ManageAgeGateSettings::class,
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

it('rejects invalid hex colors on the appearance page', function (string $field, string $value) {
    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm([$field => $value])
        ->call('save')
        ->assertHasFormErrors([$field])
        ->assertSee('Укажите цвет в формате');
})->with([
    ['primary_color', 'red'],
    ['primary_color', '#12345'],
    ['accent_color', '#gggggg'],
    ['accent_color', 'rgb(1,2,3)'],
]);

it('accepts short and long hex colors', function () {
    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['primary_color' => '#abc', 'accent_color' => '#A1B2C3'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(AppearanceSettings::class)->primary_color)->toBe('#abc');
});

it('deletes the old logo from the public disk when it is replaced', function () {
    Storage::fake('public');
    Storage::disk('public')->put('settings/old.png', 'old');
    $settings = app(GeneralSettings::class);
    $settings->logo = 'settings/old.png';
    $settings->save();

    Livewire::test(ManageGeneralSettings::class)
        ->set('data.logo', []) // the user removes the old file first, then uploads a new one
        ->fillForm(['logo' => UploadedFile::fake()->image('logo.png', 40, 40)])
        ->call('save')
        ->assertHasNoFormErrors();

    $new = app(GeneralSettings::class)->logo;

    expect($new)->toStartWith('settings/')->not->toBe('settings/old.png');
    Storage::disk('public')->assertMissing('settings/old.png');
    Storage::disk('public')->assertExists($new);
});

it('deletes the old file when a settings image is cleared', function (string $page, string $settingsClass, string $field) {
    Storage::fake('public');
    Storage::disk('public')->put('settings/to-clear.png', 'x');
    $settings = app($settingsClass);
    $settings->{$field} = 'settings/to-clear.png';
    $settings->save();

    Livewire::test($page)
        ->fillForm([$field => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app($settingsClass)->{$field})->toBeNull();
    Storage::disk('public')->assertMissing('settings/to-clear.png');
})->with([
    'logo' => [ManageGeneralSettings::class, GeneralSettings::class, 'logo'],
    'favicon' => [ManageGeneralSettings::class, GeneralSettings::class, 'favicon'],
    'placeholder' => [ManageAppearanceSettings::class, AppearanceSettings::class, 'placeholder_image'],
    'og image' => [ManageSeoSettings::class, SeoSettings::class, 'og_image'],
]);

it('keeps the file when a settings page is saved without touching it', function () {
    Storage::fake('public');
    Storage::disk('public')->put('settings/keep.png', 'x');
    $settings = app(GeneralSettings::class);
    $settings->logo = 'settings/keep.png';
    $settings->save();

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['shop_name' => 'Другое имя'])
        ->call('save')
        ->assertHasNoFormErrors();

    Storage::disk('public')->assertExists('settings/keep.png');
});

it('never deletes replaced files outside the settings directory', function () {
    Storage::fake('public');
    Storage::disk('public')->put('products/photo.png', 'x');
    $settings = app(GeneralSettings::class);
    $settings->logo = 'products/photo.png';
    $settings->save();

    Livewire::test(ManageGeneralSettings::class)
        ->fillForm(['logo' => null])
        ->call('save');

    Storage::disk('public')->assertExists('products/photo.png');
});
