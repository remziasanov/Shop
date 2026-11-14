<?php

declare(strict_types=1);

use App\Services\Media\AppIconGenerator;
use App\Services\Pwa\PwaService;
use App\Settings\AppearanceSettings;
use App\Settings\GeneralSettings;
use App\Support\TelegramMode;
use Illuminate\Support\Facades\Storage;

/**
 * A square PNG of the given side (GD only).
 */
function pwaPng(int $side = 600, string $color = '#2563eb'): string
{
    $image = imagecreatetruecolor($side, $side);
    [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
    imagefill($image, 0, 0, (int) imagecolorallocate($image, $r, $g, $b));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * Stores an icon and switches the app on, generating the icon set like the admin page does.
 *
 * @param  array<string, mixed>  $values
 */
function enablePwa(array $values = []): AppearanceSettings
{
    Storage::fake('public');
    Storage::disk('public')->put('settings/icon.png', pwaPng());

    $settings = app(AppearanceSettings::class);
    $settings->pwa_enabled = true;
    $settings->pwa_icon = 'settings/icon.png';

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();
    app(AppIconGenerator::class)->sync($settings);

    return $settings;
}

beforeEach(function () {
    $this->withoutVite();
});

it('answers 404 while the app is off (MB-20)', function () {
    $this->get('/manifest.webmanifest')->assertNotFound();
});

it('answers 404 when switched on without an icon (MB-21)', function () {
    $settings = app(AppearanceSettings::class);
    $settings->pwa_enabled = true;
    $settings->save();

    $this->get('/manifest.webmanifest')->assertNotFound();
});

it('serves the manifest built from the settings with the manifest content type (MB-20)', function () {
    $general = app(GeneralSettings::class);
    $general->shop_name = 'Лавка Тест';
    $general->save();

    enablePwa(['pwa_short_name' => 'Лавка', 'pwa_theme_color' => '#112233', 'pwa_background_color' => '#ffeedd']);

    $response = $this->get('/manifest.webmanifest')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/manifest+json');

    $manifest = $response->json();
    $hash = app(AppearanceSettings::class)->pwa_icons_hash;

    expect($manifest['name'])->toBe('Лавка Тест')
        ->and($manifest['short_name'])->toBe('Лавка')
        ->and($manifest['start_url'])->toBe('/?utm_source=pwa')
        ->and($manifest['scope'])->toBe('/')
        ->and($manifest['display'])->toBe('standalone')
        ->and($manifest['theme_color'])->toBe('#112233')
        ->and($manifest['background_color'])->toBe('#ffeedd')
        ->and($manifest['lang'])->toBe('ru')
        ->and($manifest['icons'])->toHaveCount(3)
        ->and(array_column($manifest['icons'], 'sizes'))->toBe(['192x192', '512x512', '512x512'])
        ->and(array_column($manifest['icons'], 'purpose'))->toBe(['any', 'any', 'maskable'])
        ->and($manifest['icons'][2]['src'])->toContain('app-icons/icon-512-maskable-'.$hash);
});

it('uses an own name, cuts a long short name to 12 characters and follows saved changes at once (MB-20)', function () {
    $settings = enablePwa(['pwa_name' => 'Большой магазин мебели', 'pwa_short_name' => 'Очень длинное короткое имя']);

    $this->get('/manifest.webmanifest')->assertJsonPath('name', 'Большой магазин мебели')
        ->assertJsonPath('short_name', 'Очень длинно');

    $settings->pwa_name = 'Другое имя';
    $settings->save();

    $this->get('/manifest.webmanifest')->assertJsonPath('name', 'Другое имя');
});

it('adds manifest, theme-color and apple-touch-icon to the head only when enabled (MB-20)', function () {
    $this->get('/')->assertOk()
        ->assertDontSee('rel="manifest"', false)
        ->assertDontSee('name="theme-color"', false)
        ->assertDontSee('apple-touch-icon', false);

    enablePwa(['pwa_theme_color' => '#112233']);

    $this->get('/')->assertOk()
        ->assertSee('<link rel="manifest" href="'.route('pwa.manifest').'">', false)
        ->assertSee('<meta name="theme-color" content="#112233">', false)
        ->assertSee('rel="apple-touch-icon" href="', false);
});

it('shows the install hint partials only when the app and the hint are on (MB-22)', function () {
    $this->get('/')->assertDontSee('data-pwa-ios-sheet', false);

    $settings = enablePwa();
    $this->get('/')->assertSee('data-pwa-ios-sheet', false)->assertSee('На экран Домой');

    $settings->pwa_install_hint = false;
    $settings->save();
    $this->get('/')->assertDontSee('data-pwa-ios-sheet', false);
});

it('never offers the install hint in the Telegram mode (MB-22)', function () {
    enablePwa();

    $this->withSession([TelegramMode::SESSION_KEY => true])->get('/')
        ->assertOk()
        ->assertDontSee('data-pwa-ios-sheet', false);
});

it('renders the back bar for the installed app only on inner pages (MB-23)', function () {
    enablePwa();

    $this->get('/')->assertDontSee('data-pwa-back', false);
    $this->get('/catalog')->assertSee('data-pwa-back', false);
});

it('reports the app as enabled only with an icon set (MB-21)', function () {
    expect(app(PwaService::class)->enabled())->toBeFalse();

    enablePwa();

    expect(app(PwaService::class)->enabled())->toBeTrue();
});
