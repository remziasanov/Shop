<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Filament\Pages\ThemePhonePreview;
use App\Models\Category;
use App\Models\User;
use App\Services\Themes\ThemePreview;
use App\Services\Themes\ThemeRegistry;
use App\Settings\AppearanceSettings;
use Livewire\Livewire;

/*
 * Stage 23 acceptance scenario (STAGES.md): the administrator switches a theme on, changes the five colors and the two
 * fonts, looks at the phone preview, returns the theme's values and goes back to `default`.
 */

it('runs the administrator scenario end to end', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    Category::factory()->create();
    $opalubka = app(ThemeRegistry::class)->find('opalubka');
    $settings = fn (): AppearanceSettings => app(AppearanceSettings::class)->refresh();

    // 1. Switch the theme on.
    Livewire::test(ManageAppearanceSettings::class)->call('selectTheme', 'opalubka')->assertHasNoErrors();
    expect($settings()->theme_id)->toBe('opalubka');

    // 2. Five colors and two fonts.
    $colors = ['bg' => '#F2F0EC', 'surface' => '#FFFFFF', 'ink' => '#111111', 'primary' => '#0B5FFF', 'accent' => '#222222'];
    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['ov_colors' => $colors, 'ov_fonts' => ['display' => 'Manrope', 'body' => 'Golos Text']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($settings()->theme_overrides['opalubka'])->toEqual(['colors' => $colors, 'fonts' => ['display' => 'Manrope', 'body' => 'Golos Text']]);
    $this->get(route('home'))->assertOk()
        ->assertSee('--shop-primary: #0b5fff;', false)
        ->assertSee('"Manrope"', false);

    // 3. Phone preview of the same theme: the frame page and the storefront inside it.
    Livewire::test(ManageAppearanceSettings::class)->call('previewTheme', 'opalubka');
    expect(session(ThemePreview::SESSION_KEY)['theme_id'])->toBe('opalubka');
    $this->get(ThemePhonePreview::getUrl())->assertOk()->assertSee('width="390"', false)->assertSee('height="844"', false);
    $preview = $this->get(route('home'))->assertOk()->assertSee('data-theme-preview-bar', false);
    expect($preview->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    // 4. Return the theme's values.
    Livewire::test(ManageAppearanceSettings::class)->call('resetColors')->call('resetFonts');
    expect($settings()->theme_overrides)->toBe([]);

    // 5. Back to default: the storefront looks as before themes.
    Livewire::test(ManageAppearanceSettings::class)->call('selectTheme', 'default')->assertHasNoErrors();
    session()->forget(ThemePreview::SESSION_KEY);

    expect($settings()->theme_id)->toBe('default');
    $this->get(route('home'))->assertOk()
        ->assertSee('--shop-primary: #2563eb;', false)
        ->assertDontSee('data-contrast', false)
        ->assertDontSee('--shop-primary: '.strtolower($opalubka->colors['primary']), false);
});
