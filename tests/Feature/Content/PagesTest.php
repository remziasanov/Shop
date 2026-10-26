<?php

declare(strict_types=1);

use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\User;
use App\Services\Content\DefaultPageTemplates;
use App\Settings\LegalSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

dataset('staff', [
    'admin' => fn () => User::factory()->admin()->create(),
    'manager' => fn () => User::factory()->manager()->create(),
]);

beforeEach(function () {
    $this->withoutVite();
});

it('ships five template pages (consent and policy active, others drafts) and points the legal settings to them (SF-70, SF-71)', function () {
    $pages = Page::query()->whereIn('slug', array_column(DefaultPageTemplates::all(), 'slug'))->get();

    expect($pages)->toHaveCount(5)
        ->and($pages->filter(fn (Page $p) => $p->is_active)->pluck('slug')->sort()->values()->all())->toBe(collect([DefaultPageTemplates::PRIVACY_SLUG, DefaultPageTemplates::CONSENT_SLUG])->sort()->values()->all())
        ->and($pages->every(fn (Page $p) => str_contains((string) $p->content, DefaultPageTemplates::MARKER)))->toBeTrue()
        ->and($pages->pluck('content')->join(' '))->toContain('[ИНН]');

    $legal = app(LegalSettings::class);

    expect($legal->privacy_page_id)->toBe($pages->firstWhere('slug', DefaultPageTemplates::PRIVACY_SLUG)->id)
        ->and($legal->consent_page_id)->toBe($pages->firstWhere('slug', DefaultPageTemplates::CONSENT_SLUG)->id)
        ->and($legal->privacy_page_id)->not->toBe($legal->consent_page_id);
});

it('manages pages in the admin panel (AD-60)', function (User $user) {
    $this->actingAs($user);

    Livewire::test(ListPages::class)->assertSuccessful();

    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'Гарантия', 'content' => '<p>Текст</p>', 'is_active' => true, 'meta_title' => 'Гарантия — условия'])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = Page::where('title', 'Гарантия')->firstOrFail();
    expect($page->slug)->toBe('garantiya')
        ->and($page->meta_title)->toBe('Гарантия — условия');

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['title' => 'Гарантия 2', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->title)->toBe('Гарантия 2')->and($page->is_active)->toBeFalse();

    Livewire::test(ListPages::class)
        ->callAction(TestAction::make('delete')->table($page));

    expect(Page::find($page->id))->toBeNull();
})->with('staff');

it('validates SEO field length', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'X', 'meta_description' => str_repeat('a', 256)])
        ->call('create')
        ->assertHasFormErrors(['meta_description']);
});

it('offers the open-on-site link for active pages only (AD-05)', function () {
    $this->actingAs(User::factory()->admin()->create());
    $active = Page::factory()->create();
    $draft = Page::factory()->inactive()->create();

    Livewire::test(EditPage::class, ['record' => $active->getRouteKey()])
        ->assertActionVisible('open_on_site');
    Livewire::test(EditPage::class, ['record' => $draft->getRouteKey()])
        ->assertActionHidden('open_on_site');
});

it('generates the slug and redirects the old address with 301 on slug change (D-018)', function () {
    $page = Page::factory()->create(['title' => 'Старое название']);
    expect($page->slug)->toBe('staroe-nazvanie');

    $page->update(['slug' => 'novoe']);

    expect(Redirect::where('from_path', '/page/staroe-nazvanie')->value('to_path'))->toBe('/page/novoe');
    $this->get('/page/staroe-nazvanie')->assertStatus(301)->assertRedirect('/page/novoe');
    $this->get('/page/novoe')->assertOk();
});

it('keeps slugs unique', function () {
    Page::factory()->create(['title' => 'Дубль']);
    $second = Page::factory()->create(['title' => 'Дубль']);

    expect($second->slug)->toBe('dubl-2');
});

it('sanitizes the content on save and on output (NF-10)', function () {
    $page = Page::factory()->create([
        'content' => '<p onclick="x()">Привет</p><script>alert(1)</script><a href="javascript:alert(2)">link</a>',
    ]);

    expect($page->content)->not->toContain('script')->not->toContain('onclick')->not->toContain('javascript:');

    // A row written around the model is still cleaned when rendered.
    Page::query()->whereKey($page->id)->update(['content' => '<p>Ок</p><script>alert(3)</script>']);

    $this->get(route('page.show', $page->slug))->assertOk()->assertSee('Ок')->assertDontSee('alert(3)', false);
});

it('shows an active page with breadcrumbs and 404s an inactive one (SF-70, SF-06)', function () {
    $page = Page::factory()->create(['title' => 'О нас', 'content' => '<p>Мы продаём всякое</p>']);
    $draft = Page::factory()->inactive()->create();

    $this->get(route('page.show', $page->slug))
        ->assertOk()
        ->assertSee('Мы продаём всякое')
        ->assertSeeInOrder(['Главная', 'О нас'])
        ->assertSee('<title>О нас', false);

    $this->get(route('page.show', $draft->slug))->assertNotFound();
    $this->get('/page/net-takoy')->assertNotFound();
});
