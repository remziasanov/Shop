<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Models\User;
use App\Services\Themes\ThemeMiniature;
use App\Services\Themes\ThemePreview;
use App\Services\Themes\ThemeRegistry;
use App\Services\Themes\ThemeResolver;
use App\Settings\AppearanceSettings;
use Livewire\Livewire;

/*
 * D-210: miniature cards in the theme gallery and the preview overlay over the page.
 */

beforeEach(function (): void {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('draws a miniature on every card with the colors and fonts of that theme as css variables (D-210)', function (): void {
    $html = Livewire::test(ManageAppearanceSettings::class)->html();
    $registry = app(ThemeRegistry::class);

    expect(substr_count($html, 'data-theme-miniature'))->toBe(51)
        ->and($html)->toContain('aria-hidden="true" data-theme-miniature');

    $volt = $registry->find('volt');
    $resolved = app(ThemeResolver::class)->resolve($volt);
    $style = app(ThemeMiniature::class)->style($resolved);

    expect($style)->toContain('--c-primary:'.strtolower($volt->colors['primary']).';')
        ->toContain('--c-bg:'.strtolower($volt->colors['bg']).';')
        ->toContain('--c-hero-bg:'.strtolower($volt->colors['heroBg']).';')
        ->and($html)->toContain('--c-primary:'.strtolower($volt->colors['primary']).';')
        ->and($style)->toMatch('/--c-font-d:"[A-Za-z0-9 \-]+",/');
});

it('emits font faces only for the fonts that cards use, with swap and unicode ranges', function (): void {
    $html = Livewire::test(ManageAppearanceSettings::class)->html();

    preg_match_all('/@font-face\{/', $html, $m);

    expect(count($m[0]))->toBeGreaterThan(10)
        ->and($html)->toContain('font-display:swap')->toContain('unicode-range:U+0301,U+0400-045F')
        ->and($html)->toContain('content-visibility: auto');
});

it('lays the cards out in three columns, two on tablets and one on phones', function (): void {
    $html = Livewire::test(ManageAppearanceSettings::class)->html();

    expect($html)->toContain('shop-tg-grid shop-tg-grid--3')
        ->toContain('grid-template-columns: repeat(3, minmax(0, 1fr))')
        ->toContain('@media (max-width: 1023px) { .shop-tg-grid { grid-template-columns: repeat(2')
        ->toContain('@media (max-width: 639px) { .shop-tg-grid { grid-template-columns: minmax(0, 1fr)');
});

it('draws the miniature of the edited theme with the unsaved form colors', function (): void {
    $html = Livewire::test(ManageAppearanceSettings::class)
        ->call('selectTheme', 'volt')
        ->fillForm(['ov_colors' => ['primary' => '#ABCDEF']])
        ->html();

    expect($html)->toContain('--c-primary:#abcdef;');
});

it('never lets an unsanitized color reach the css of a card', function (): void {
    app(AppearanceSettings::class)->theme_overrides = ['volt' => ['colors' => ['primary' => 'red;}</style><script>alert(1)</script>', 'accent' => '#00AA00']]];
    app(AppearanceSettings::class)->save();

    $html = Livewire::test(ManageAppearanceSettings::class)->html();

    // The Livewire snapshot holds the raw settings (escaped in an attribute); the markup and the css of the cards must not.
    $markup = (string) preg_replace('/wire:snapshot="[^"]*"/', '', $html);

    expect($markup)->not->toContain('alert(1)')->not->toContain('red;}')
        ->and($html)->toContain('--c-accent:#00aa00;');

    $resolved = app(ThemeResolver::class)->resolve(app(ThemeRegistry::class)->find('volt'), ['colors' => ['primary' => 'url(x);}', 'bg' => '#12']]);
    $style = app(ThemeMiniature::class)->style($resolved);

    expect($style)->not->toContain('url(')->not->toContain('}');
    preg_match_all('/--c-(?:bg|surface|ink|muted|line|tile|primary|on-primary|accent|on-accent|hero-bg|hero-ink):([^;]*);/', $style, $values);
    expect($values[1])->toHaveCount(12);

    foreach ($values[1] as $value) {
        expect($value)->toMatch('/\A#[0-9a-f]{6}\z/');
    }
});

it('starts the preview for the overlay and opens it without window.open (TH-27, D-210)', function (): void {
    $component = Livewire::test(ManageAppearanceSettings::class)->call('previewTheme', 'volt');

    $component->assertDispatched('theme-preview-open', id: 'volt', name: 'Вольт', active: false, url: route('home'));

    expect(session(ThemePreview::SESSION_KEY))->toMatchArray(['theme_id' => 'volt', 'embed' => true]);

    $html = Livewire::test(ManageAppearanceSettings::class)->html();

    expect($html)->toContain('data-theme-preview-overlay')->toContain('data-theme-preview-frame')
        ->toContain('x-trap.noscroll')->toContain('x-on:keydown.escape.window')->toContain('x-on:click.self="close()"')
        ->toContain('Компьютер')->toContain('Телефон')
        ->and($html)->not->toContain('window.open');
});

it('steps to the previous and next theme from the overlay and restarts the preview', function (): void {
    $registry = app(ThemeRegistry::class);
    $ids = array_keys($registry->all());

    $component = Livewire::test(ManageAppearanceSettings::class)->call('previewTheme', $ids[1]);

    $component->call('previewStep', 1)->assertDispatched('theme-preview-open', id: $ids[2], index: 2, total: count($ids));
    expect(session(ThemePreview::SESSION_KEY)['theme_id'])->toBe($ids[2]);

    $component->call('previewStep', -1)->call('previewStep', -1)->assertDispatched('theme-preview-open', id: $ids[0]);
    expect(session(ThemePreview::SESSION_KEY)['theme_id'])->toBe($ids[0]);

    // Stepping back from the first theme wraps to the last one.
    $component->call('previewStep', -1);
    expect(session(ThemePreview::SESSION_KEY)['theme_id'])->toBe($ids[count($ids) - 1]);
});

it('selects the previewed theme from the overlay, ends the preview and closes the overlay', function (): void {
    Livewire::test(ManageAppearanceSettings::class)
        ->call('previewTheme', 'volt')
        ->call('applyPreview')
        ->assertSet('data.theme_id', 'volt')
        ->assertDispatched('theme-preview-close')
        ->assertNotified('Тема «Вольт» включена')
        ->assertSee('data-theme-active', false);

    expect(app(AppearanceSettings::class)->refresh()->theme_id)->toBe('volt')
        ->and(session()->has(ThemePreview::SESSION_KEY))->toBeFalse();
});

it('exits the preview when the overlay closes', function (): void {
    Livewire::test(ManageAppearanceSettings::class)->call('previewTheme', 'volt')->call('closePreview');

    expect(session()->has(ThemePreview::SESSION_KEY))->toBeFalse();
});

it('does nothing from the overlay without a started preview', function (): void {
    Livewire::test(ManageAppearanceSettings::class)->call('previewStep', 1)->call('applyPreview')->assertNotDispatched('theme-preview-open');

    expect(app(AppearanceSettings::class)->refresh()->theme_id)->toBe('default');
});

it('refuses the overlay actions to staff without the update right (TH-26)', function (): void {
    $this->actingAs(User::factory()->withPermissions(['settings.appearance.view'])->create());

    Livewire::test(ManageAppearanceSettings::class)->assertDontSee('data-theme-preview-overlay', false);

    foreach ([['previewTheme', ['volt']], ['previewStep', [1]], ['applyPreview', []], ['closePreview', []]] as [$method, $args]) {
        Livewire::test(ManageAppearanceSettings::class)->call($method, ...$args)->assertForbidden();
    }

    expect(session()->has(ThemePreview::SESSION_KEY))->toBeFalse()
        ->and(app(AppearanceSettings::class)->refresh()->theme_id)->toBe('default');
});

it('hides the storefront bar only for an overlay preview shown in a frame (D-210)', function (): void {
    $state = [ThemePreview::SESSION_KEY => ['theme_id' => 'monohrom', 'overrides' => [], 'embed' => true]];

    // Framed by the admin overlay: no bar, still private and not indexable.
    $framed = $this->withSession($state)->withHeaders(['Sec-Fetch-Dest' => 'iframe'])->get(route('home'))->assertOk();

    expect($framed->getContent())->not->toContain('data-theme-preview-bar');
    expect($framed->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($framed->headers->get('X-Robots-Tag'))->toContain('noindex');

});

it('keeps the storefront bar in an own tab of the overlay session and when the browser sends no frame header', function (): void {
    $state = [ThemePreview::SESSION_KEY => ['theme_id' => 'monohrom', 'overrides' => [], 'embed' => true]];

    $this->withSession($state)->get(route('home'))->assertOk()->assertSee('data-theme-preview-bar', false);
    $this->withSession($state)->withHeaders(['Sec-Fetch-Dest' => 'document'])->get(route('home'))->assertOk()->assertSee('data-theme-preview-bar', false);
});

it('keeps the bar inside a frame for a preview that did not come from the overlay (phone page, TH-29)', function (): void {
    $plain = [ThemePreview::SESSION_KEY => ['theme_id' => 'monohrom', 'overrides' => []]];

    $this->withSession($plain)->withHeaders(['Sec-Fetch-Dest' => 'iframe'])->get(route('home'))->assertOk()->assertSee('data-theme-preview-bar', false);
});

it('keeps the embed flag when the storefront bar steps to another theme', function (): void {
    $this->withSession([ThemePreview::SESSION_KEY => ['theme_id' => 'monohrom', 'overrides' => [], 'embed' => true]])
        ->post(route('theme.preview.step'), ['direction' => 'next'])->assertRedirect();

    expect(session(ThemePreview::SESSION_KEY)['embed'])->toBeTrue();
});

it('does not hide the bar for a guest with a forged embed flag (TH-30)', function (): void {
    auth()->logout();
    $this->flushSession();

    $html = $this->withSession([ThemePreview::SESSION_KEY => ['theme_id' => 'monohrom', 'overrides' => [], 'embed' => true]])
        ->withHeaders(['Sec-Fetch-Dest' => 'iframe'])->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('data-theme-preview-bar')->and($html)->toContain('--shop-primary: #2563eb;');
});
