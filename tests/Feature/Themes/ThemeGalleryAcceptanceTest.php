<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Models\Category;
use App\Models\User;
use App\Settings\AppearanceSettings;
use Livewire\Livewire;

/*
 * Stage 22 acceptance (STAGES.md): the admin gallery holds 51 themes, and each one can be switched on and back off
 * without errors. Rendering every theme on the main pages is covered by ThemeStorefrontTest (TH-32).
 */

beforeEach(function (): void {
    $this->withoutVite();
    $this->actingAs(User::factory()->admin()->create());
});

/** @return list<string> */
function stage22GalleryIds(): array
{
    return array_merge(['default'], array_column(json_decode((string) file_get_contents(dirname(__DIR__, 3).'/resources/themes/themes.json'), true)['themes'], 'id'));
}

it('shows 51 themes in the gallery: default and the 50 approved ones', function (): void {
    $html = Livewire::test(ManageAppearanceSettings::class)->html();

    preg_match_all('/data-theme-card="([a-z0-9-]+)"/', $html, $m);

    expect(array_values(array_unique($m[1])))->toHaveCount(51)
        ->and(array_diff(stage22GalleryIds(), $m[1]))->toBe([]);
});

it('switches every theme on and back to default without errors', function (string $id): void {
    Category::factory()->create();

    Livewire::test(ManageAppearanceSettings::class)
        ->call('selectTheme', $id)
        ->assertHasNoErrors()
        ->assertSet('data.theme_id', $id);

    expect(app(AppearanceSettings::class)->refresh()->theme_id)->toBe($id);
    $this->get(route('home'))->assertOk();

    Livewire::test(ManageAppearanceSettings::class)
        ->call('selectTheme', 'default')
        ->assertHasNoErrors()
        ->assertSet('data.theme_id', 'default');

    expect(app(AppearanceSettings::class)->refresh()->theme_id)->toBe('default');
    $this->get(route('home'))->assertOk();
})->with(fn () => stage22GalleryIds());
