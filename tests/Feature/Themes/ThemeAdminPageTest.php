<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Models\AdminAuditEntry;
use App\Models\User;
use App\Services\Themes\ThemeManager;
use App\Services\Themes\ThemePreview;
use App\Settings\AppearanceSettings;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

function appearance(): AppearanceSettings
{
    return app(AppearanceSettings::class)->refresh();
}

it('shows the gallery with default first, groups, swatches, fonts and the active mark (TH-22)', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->assertSee('data-theme-gallery', false)
        ->assertSee('data-theme-card="default"', false)
        ->assertSee('data-theme-card="volt"', false)
        ->assertSee('Бытовая и компьютерная техника')
        ->assertSee('Вольт')
        ->assertSee('Golos Text')
        ->assertSee('Предпросмотр')
        ->assertSee('Выбрать')
        ->assertSee('Включена')
        ->assertSee('data-theme-active', false);
});

it('keeps the old fields of the page working (TH-25, MB-07)', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->assertFormSet(['grid_columns' => 4, 'per_page' => 24, 'mobile_columns' => 2])
        ->fillForm(['grid_columns' => 3, 'mobile_columns' => 1, 'per_page' => 36])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(appearance()->grid_columns)->toBe(3)->and(appearance()->mobile_columns)->toBe(1)->and(appearance()->per_page)->toBe(36);
});

it('selects a theme in one action and writes the journal (TH-04, TH-26)', function (): void {
    Livewire::test(ManageAppearanceSettings::class)->call('selectTheme', 'volt')->assertSet('data.theme_id', 'volt');

    $entry = AdminAuditEntry::query()->where('action', AuditAction::ThemeChanged->value)->firstOrFail();

    expect(appearance()->theme_id)->toBe('volt')
        ->and($entry->user_id)->toBe($this->admin->id)
        ->and($entry->data)->toEqual(['from' => 'default', 'to' => 'volt']);
});

it('ignores an unknown theme id', function (): void {
    Livewire::test(ManageAppearanceSettings::class)->call('selectTheme', 'nope');

    expect(appearance()->theme_id)->toBe('default');
});

it('saves five colors and two fonts for the active theme with a journal entry (TH-23, TH-24, TH-26)', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->call('selectTheme', 'volt')
        ->fillForm(['ov_colors' => ['primary' => '#FF5500', 'bg' => '#101010', 'ink' => '#F0F0F0'], 'ov_fonts' => ['display' => 'Manrope', 'body' => 'Golos Text']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(appearance()->theme_overrides['volt'])->toEqual([
        'colors' => ['bg' => '#101010', 'ink' => '#F0F0F0', 'primary' => '#FF5500'],
        'fonts' => ['display' => 'Manrope', 'body' => 'Golos Text'],
    ])
        ->and(appearance()->primary_color)->toBe('#FF5500')
        ->and(AdminAuditEntry::query()->where('action', AuditAction::ThemeColorsChanged->value)->count())->toBe(1)
        ->and(AdminAuditEntry::query()->where('action', AuditAction::ThemeFontsChanged->value)->count())->toBe(1);

    $this->flushSession();
    $this->actingAs($this->admin)->get(route('home'))->assertSee('--shop-primary: #ff5500;', false);
});

it('does not journal a save with unchanged overrides', function (): void {
    Livewire::test(ManageAppearanceSettings::class)->call('save');

    expect(AdminAuditEntry::query()->whereIn('action', [AuditAction::ThemeColorsChanged->value, AuditAction::ThemeFontsChanged->value])->count())->toBe(0);
});

it('stores overrides per theme and restores them on switching back (TH-04)', function (): void {
    $page = Livewire::test(ManageAppearanceSettings::class)
        ->call('selectTheme', 'volt')
        ->fillForm(['ov_colors' => ['primary' => '#FF0000']])
        ->call('save')
        ->call('selectTheme', 'monohrom')
        ->assertSet('data.ov_colors', [])
        ->fillForm(['ov_colors' => ['primary' => '#00FF00']])
        ->call('save')
        ->call('selectTheme', 'volt')
        ->assertSet('data.ov_colors.primary', '#FF0000');

    expect(appearance()->theme_overrides)->toHaveKeys(['volt', 'monohrom'])->and(appearance()->theme_overrides['monohrom']['colors']['primary'])->toBe('#00FF00');
    $page->assertSet('data.theme_id', 'volt');
});

it('resets theme colors and theme fonts separately (TH-23, TH-24)', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->call('selectTheme', 'volt')
        ->fillForm(['ov_colors' => ['primary' => '#FF0000'], 'ov_fonts' => ['display' => 'Manrope']])
        ->call('save')
        ->call('resetColors')
        ->assertSet('data.ov_colors', []);

    expect(appearance()->theme_overrides['volt'])->toBe(['fonts' => ['display' => 'Manrope']]);

    Livewire::test(ManageAppearanceSettings::class)->call('resetFonts');

    expect(appearance()->theme_overrides)->toBe([])
        ->and(appearance()->primary_color)->toBe('#1F4FE0');
});

it('rejects invalid colors and unknown fonts on the form (TH-09, TH-20)', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['ov_fonts' => ['display' => 'Nope']])
        ->call('save')
        ->assertHasFormErrors(['ov_fonts.display']);

    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['ov_fonts' => ['body' => 'Alice']])
        ->call('save')
        ->assertHasFormErrors(['ov_fonts.body']);

    expect(appearance()->theme_overrides)->toBe([]);
});

it('drops invalid values even if they bypass the form (TH-09)', function (): void {
    app(ThemeManager::class)->saveOverrides($this->admin, 'default', [
        'colors' => ['primary' => 'red;}</style>', 'accent' => '#00AA00'],
        'fonts' => ['display' => 'Nope', 'body' => 'Alice'],
    ]);

    expect(appearance()->theme_overrides)->toBe(['default' => ['colors' => ['accent' => '#00AA00']]]);
});

it('warns about low contrast but still saves (TH-08)', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['ov_colors' => ['bg' => '#FFFFFF', 'ink' => '#EEEEEE']])
        ->call('save')
        ->assertNotified('Низкий контраст');

    expect(appearance()->theme_overrides['default']['colors']['ink'])->toBe('#EEEEEE');
});

it('does not warn when the contrast is fine', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->fillForm(['ov_colors' => ['primary' => '#1D4ED8']])
        ->call('save')
        ->assertNotNotified('Низкий контраст');
});

it('starts a preview with the unsaved form colors for the form theme and stored ones for another (TH-27)', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->call('selectTheme', 'volt')
        ->fillForm(['ov_colors' => ['primary' => '#ABCDEF']])
        ->call('previewTheme', 'volt');

    expect(session(ThemePreview::SESSION_KEY))->toBe(['theme_id' => 'volt', 'overrides' => ['colors' => ['primary' => '#ABCDEF']]])
        ->and(appearance()->theme_overrides)->toBe([]);

    Livewire::test(ManageAppearanceSettings::class)->call('previewTheme', 'monohrom');

    expect(session(ThemePreview::SESSION_KEY))->toBe(['theme_id' => 'monohrom', 'overrides' => []]);
});

it('is read-only for staff with only the view right (TH-26)', function (): void {
    $viewer = User::factory()->withPermissions(['settings.appearance.view'])->create();
    $this->actingAs($viewer);

    Livewire::test(ManageAppearanceSettings::class)
        ->assertDontSee('wire:click="selectTheme', false)
        ->call('selectTheme', 'volt')
        ->assertForbidden();

    expect(appearance()->theme_id)->toBe('default');
});

it('is closed to staff without any appearance right', function (): void {
    $this->actingAs(User::factory()->withPermissions(['settings.general.view'])->create());

    expect(ManageAppearanceSettings::canAccess())->toBeFalse();
});
