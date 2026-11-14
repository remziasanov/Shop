<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Filament\Pages\ThemePhonePreview;
use App\Models\AdminAuditEntry;
use App\Models\User;
use App\Services\Home\HomePageRenderer;
use App\Services\Themes\ThemePreview;
use App\Services\Themes\ThemeRegistry;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/home.php';

beforeEach(function (): void {
    $this->withoutVite();
});

function previewSession(string $theme = 'monohrom', array $overrides = []): array
{
    return [ThemePreview::SESSION_KEY => ['theme_id' => $theme, 'overrides' => $overrides]];
}

function previewStaff(array $permissions = ['settings.appearance.update']): User
{
    return User::factory()->withPermissions($permissions)->create();
}

function homeCacheKeys(): array
{
    return array_values(array_filter(
        (fn (): array => array_keys($this->storage))->call(Cache::getStore()),
        fn (string $key): bool => str_starts_with($key, 'home:'),
    ));
}

it('shows the previewed theme with the bar to staff with the right (TH-27)', function (): void {
    $html = $this->actingAs(previewStaff())
        ->withSession(previewSession('monohrom', ['colors' => ['primary' => '#FF0000']]))
        ->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('--shop-primary: #ff0000;')->toContain('--shop-bg: #ffffff;')
        ->and($html)->toContain('data-theme-preview-bar')->toContain('Монохром')
        ->and($html)->toContain(route('theme.preview.apply'))->toContain(route('theme.preview.exit'))
        ->and($html)->toContain('Компьютер')->toContain('Телефон');
});

it('serves previews as private, no-store and not indexable (TH-30)', function (): void {
    $response = $this->actingAs(previewStaff())->withSession(previewSession())->get(route('home'))->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('X-Robots-Tag'))->toContain('noindex');
});

it('ignores a forged preview flag of a guest (TH-30)', function (): void {
    $settings = app(AppearanceSettings::class);
    $settings->theme_id = 'volt';
    $settings->save();

    $response = $this->withSession(previewSession('monohrom'))->get(route('home'))->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('--shop-primary: #1f4fe0;')->not->toContain('data-theme-preview-bar')
        ->and($response->headers->get('X-Robots-Tag'))->toBeNull();
});

it('ignores the flag of staff without the appearance update right or an inactive account', function (): void {
    $view = previewStaff(['settings.appearance.view']);
    $inactive = User::factory()->inactive()->withPermissions(['settings.appearance.update'])->create();

    foreach ([$view, $inactive] as $user) {
        $html = $this->actingAs($user)->withSession(previewSession())->get(route('home'))->getContent();
        expect($html)->not->toContain('data-theme-preview-bar')->and($html)->toContain('--shop-primary: #2563eb;');
    }
});

it('ignores an unknown theme id in the session', function (): void {
    $html = $this->actingAs(previewStaff())->withSession(previewSession('nope'))->get(route('home'))->getContent();

    expect($html)->not->toContain('data-theme-preview-bar')->and($html)->toContain('--shop-primary: #2563eb;');
});

it('does not pollute the home page cache (TH-30, TH-12)', function (): void {
    storeHomeBlocks([homeBlock('text', ['title' => 'Привет', 'body' => '<p>Текст</p>'])]);
    $this->actingAs(previewStaff())->withSession(previewSession())->get(route('home'))->assertOk();
    expect(homeCacheKeys())->toBe([]);

    auth()->logout();
    $this->flushSession();
    $this->get(route('home'))->assertOk();
    expect(homeCacheKeys())->toHaveCount(1)->and(homeCacheKeys()[0])->toBe(HomePageRenderer::cacheKey())->and(homeCacheKeys()[0])->toContain('default-');
});

it('moves to the neighbour theme with its own overrides (TH-27)', function (): void {
    $user = previewStaff();

    $this->actingAs($user)->withSession(previewSession('default'))->post(route('theme.preview.step'), ['direction' => 'next'])->assertRedirect();
    expect(session(ThemePreview::SESSION_KEY)['theme_id'])->toBe('volt');

    $this->post(route('theme.preview.step'), ['direction' => 'prev']);
    expect(session(ThemePreview::SESSION_KEY)['theme_id'])->toBe('default');

    $this->post(route('theme.preview.step'), ['direction' => 'prev']);
    expect(session(ThemePreview::SESSION_KEY)['theme_id'])->toBe(array_key_last(app(ThemeRegistry::class)->all()));
});

it('applies the preview: saves the theme and overrides and writes the journal (TH-27, TH-26)', function (): void {
    $user = previewStaff();

    $this->actingAs($user)->withSession(previewSession('volt', ['colors' => ['primary' => '#FF0000']]))
        ->post(route('theme.preview.apply'))->assertRedirect(route('home'));

    $settings = app(AppearanceSettings::class)->refresh();

    expect($settings->theme_id)->toBe('volt')
        ->and($settings->theme_overrides['volt']['colors']['primary'])->toBe('#FF0000')
        ->and(session()->has(ThemePreview::SESSION_KEY))->toBeFalse()
        ->and(AdminAuditEntry::query()->where('action', AuditAction::ThemeChanged->value)->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(AdminAuditEntry::query()->where('action', AuditAction::ThemeColorsChanged->value)->exists())->toBeTrue();

    $this->withSession([])->get(route('home'))->assertSee('--shop-primary: #ff0000;', false);
});

it('exits the preview', function (): void {
    $this->actingAs(previewStaff())->withSession(previewSession())->post(route('theme.preview.exit'))->assertRedirect(route('home'));

    expect(session()->has(ThemePreview::SESSION_KEY))->toBeFalse();
});

it('rejects preview buttons for guests and staff without the right, and apply never works on a forged flag', function (): void {
    $this->withSession(previewSession())->post(route('theme.preview.apply'))->assertForbidden();
    $this->withSession(previewSession())->post(route('theme.preview.exit'))->assertForbidden();
    $this->actingAs(previewStaff(['settings.appearance.view']))->withSession(previewSession())->post(route('theme.preview.apply'))->assertForbidden();

    expect(app(AppearanceSettings::class)->refresh()->theme_id)->toBe('default');
});

it('opens the phone frame page for staff only (TH-29)', function (): void {
    $this->actingAs(previewStaff())->get(ThemePhonePreview::getUrl())
        ->assertOk()->assertSee('data-theme-phone-frame', false)->assertSee('width="390"', false)->assertSee('height="844"', false);

    expect(session()->has(ThemePreview::SESSION_KEY))->toBeTrue();

    auth()->logout();
    $this->actingAs(previewStaff(['settings.appearance.view']))->get(ThemePhonePreview::getUrl())->assertForbidden();
});
