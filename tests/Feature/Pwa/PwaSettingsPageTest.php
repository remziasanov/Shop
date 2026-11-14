<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Models\User;
use App\Settings\AppearanceSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
});

/** A fake upload with real PNG bytes. */
function pwaUpload(int $w = 600, int $h = 600, string $name = 'icon.png'): UploadedFile
{
    $image = imagecreatetruecolor($w, $h);
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

it('shows the «Приложение на телефоне» section with the preview (MB-24)', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAppearanceSettings::class)
        ->assertSee('Приложение на телефоне')
        ->assertSee('data-pwa-preview', false)
        ->assertSee('Иконка не загружена');
});

it('disables the enable toggle with an explanation until an icon is uploaded (MB-21)', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAppearanceSettings::class)
        ->assertFormFieldIsDisabled('pwa_enabled')
        ->assertSee('Сначала загрузите иконку приложения');
});

it('cannot switch the app on without an icon even if the value is sent (MB-21)', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['pwa_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(AppearanceSettings::class)->pwa_enabled)->toBeFalse();
});

it('uploads an icon, generates the set and enables the app (MB-21, MB-24)', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm([
            'pwa_icon' => pwaUpload(),
            'pwa_enabled' => true,
            'pwa_name' => 'Лавка',
            'pwa_short_name' => 'Лавка',
            'pwa_theme_color' => '#112233',
            'pwa_background_color' => '#ffffff',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(AppearanceSettings::class);

    expect($settings->pwa_enabled)->toBeTrue()
        ->and($settings->pwa_icon)->toStartWith('settings/')
        ->and($settings->pwa_icons_hash)->toHaveLength(12);
    Storage::disk('public')->assertExists('app-icons/icon-192-'.$settings->pwa_icons_hash.'.png');

    $this->get('/manifest.webmanifest')->assertOk()->assertJsonPath('name', 'Лавка');
});

it('rejects an SVG, a small and a non-square icon on the form (MB-21, NF-11)', function (UploadedFile $file) {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['pwa_icon' => $file])
        ->call('save')
        ->assertHasFormErrors(['pwa_icon']);

    expect(app(AppearanceSettings::class)->pwa_icon)->toBeNull();
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('icon.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"></svg>'),
    'small' => fn () => pwaUpload(300, 300),
    'not square' => fn () => pwaUpload(800, 600),
]);

it('limits the short name to 12 characters (MB-20)', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['pwa_short_name' => str_repeat('я', 13)])
        ->call('save')
        ->assertHasFormErrors(['pwa_short_name' => 'max']);
});

it('regenerates the icons when only the background color is saved and removes the old files (MB-21)', function () {
    $this->actingAs(User::factory()->admin()->create());

    $page = Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['pwa_icon' => pwaUpload(), 'pwa_enabled' => true, 'pwa_background_color' => '#ffffff'])
        ->call('save')
        ->assertHasNoFormErrors();
    $first = app(AppearanceSettings::class)->pwa_icons_hash;

    $page->fillForm(['pwa_background_color' => '#202020'])->call('save')->assertHasNoFormErrors();
    $second = app(AppearanceSettings::class)->pwa_icons_hash;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing("app-icons/icon-192-{$first}.png");
    Storage::disk('public')->assertExists("app-icons/icon-192-{$second}.png");
});

it('is guarded by settings.appearance.update (MB-24)', function () {
    $viewer = staffWith(['dashboard.view', 'settings.appearance.view']);
    $this->actingAs($viewer);

    $this->get(ManageAppearanceSettings::getUrl())->assertOk();
    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['pwa_icon' => pwaUpload(), 'pwa_enabled' => true])
        ->call('save');
    expect(app(AppearanceSettings::class)->pwa_enabled)->toBeFalse()
        ->and(app(AppearanceSettings::class)->pwa_icon)->toBeNull();

    $writer = staffWith(['dashboard.view', 'settings.appearance.update']);
    $this->actingAs($writer);

    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['pwa_icon' => pwaUpload(), 'pwa_enabled' => true])
        ->call('save')
        ->assertHasNoFormErrors();
    expect(app(AppearanceSettings::class)->pwa_enabled)->toBeTrue();

    expect(ManageAppearanceSettings::settingsPermission('update'))->toBe('settings.appearance.update');
});
