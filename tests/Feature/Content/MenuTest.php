<?php

declare(strict_types=1);

use App\Enums\MenuLinkType;
use App\Enums\MenuLocation;
use App\Enums\SalesMode;
use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Livewire\Storefront\InquiryForm;
use App\Models\Category;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\LegalSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

dataset('staff', [
    'admin' => fn () => User::factory()->admin()->create(),
    'manager' => fn () => User::factory()->manager()->create(),
]);

beforeEach(function () {
    $this->withoutVite();
});

it('creates, edits and deletes menu items in the admin panel (AD-61)', function (User $user) {
    $this->actingAs($user);
    $page = Page::factory()->create(['title' => 'Доставка']);

    Livewire::test(ListMenuItems::class)->assertSuccessful();

    Livewire::test(CreateMenuItem::class)
        ->fillForm(['location' => 'footer', 'label' => 'Как доставляем', 'link_type' => 'page', 'page_id' => $page->id, 'is_active' => true, 'sort_order' => 1])
        ->call('create')
        ->assertHasNoFormErrors();

    $item = MenuItem::where('label', 'Как доставляем')->firstOrFail();
    expect($item->location)->toBe(MenuLocation::Footer)
        ->and($item->link_type)->toBe(MenuLinkType::Page)
        ->and($item->page_id)->toBe($page->id);

    Livewire::test(EditMenuItem::class, ['record' => $item->getRouteKey()])
        ->fillForm(['link_type' => 'url', 'url' => 'https://example.com/x'])
        ->call('save')
        ->assertHasNoFormErrors();

    $item->refresh();
    expect($item->link_type)->toBe(MenuLinkType::Url)->and($item->page_id)->toBeNull()->and($item->url)->toBe('https://example.com/x');

    Livewire::test(ListMenuItems::class)
        ->set('activeTab', 'footer')
        ->callAction(TestAction::make('delete')->table($item));
    expect(MenuItem::find($item->id))->toBeNull();
})->with('staff');

it('requires the target of the chosen link type', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateMenuItem::class)
        ->fillForm(['location' => 'header', 'label' => 'X', 'link_type' => 'category'])
        ->call('create')
        ->assertHasFormErrors(['category_id' => 'required']);
});

it('rejects dangerous urls in the form and never renders them (NF-10)', function (string $url) {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(CreateMenuItem::class)
        ->fillForm(['location' => 'header', 'label' => 'X', 'link_type' => 'url', 'url' => $url])
        ->call('create')
        ->assertHasFormErrors(['url']);

    // Even if such a row gets into the database, it is not shown.
    MenuItem::factory()->create(['label' => 'Опасная', 'url' => $url]);
    $this->get('/')->assertOk()->assertDontSee('Опасная');
})->with(['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.example', 'vbscript:x']);

it('accepts http, relative, tel and mailto urls', function (string $url) {
    expect(MenuItem::isSafeUrl($url))->toBeTrue();
})->with(['https://example.com/a?b=1', 'http://example.com', '/catalog', '#top', 'tel:+79990000000', 'mailto:a@example.com']);

it('reorders items by drag and drop within a menu', function () {
    $this->actingAs(User::factory()->manager()->create());
    $a = MenuItem::factory()->create(['sort_order' => 1]);
    $b = MenuItem::factory()->create(['sort_order' => 2]);

    Livewire::test(ListMenuItems::class)
        ->set('tableRecordsPerPage', 10)
        ->call('reorderTable', [$b->id, $a->id]);

    expect($b->refresh()->sort_order)->toBeLessThan($a->refresh()->sort_order);
});

it('falls back to root categories when the header menu is empty (AD-61)', function () {
    $root = Category::factory()->create(['name' => 'Корневая']);
    Category::factory()->childOf($root)->create(['name' => 'Дочерняя']);

    $this->get('/')->assertOk()->assertSee('Корневая')->assertSee('Дочерняя');
});

it('shows header menu items instead of categories and keeps their order (SF-01)', function () {
    Category::factory()->create(['name' => 'Корневая категория']);
    $page = Page::factory()->create(['title' => 'О магазине']);
    MenuItem::factory()->create(['label' => 'Второй', 'url' => 'https://example.com/two', 'sort_order' => 2]);
    MenuItem::factory()->forPage($page)->create(['label' => 'Первый', 'sort_order' => 1]);
    MenuItem::factory()->inactive()->create(['label' => 'Скрытый']);
    MenuItem::factory()->forPage(Page::factory()->inactive()->create())->create(['label' => 'Черновик']);

    $this->get(route('page.show', Page::factory()->create(['title' => 'Служебная'])->slug))->assertOk()
        ->assertSeeInOrder(['Первый', 'Второй'])
        ->assertSee(route('page.show', $page->slug, false), false)
        ->assertDontSee('Скрытый')
        ->assertDontSee('Черновик')
        ->assertDontSee('Корневая категория');
});

it('shows visible children of a category item as the second level (SF-01)', function () {
    $root = Category::factory()->create(['name' => 'Мебель']);
    Category::factory()->childOf($root)->create(['name' => 'Столы']);
    Category::factory()->childOf($root)->inactive()->create(['name' => 'Скрытые стулья']);
    MenuItem::factory()->forCategory($root)->create(['label' => 'Всё для дома']);

    $this->get(route('page.show', Page::factory()->create(['title' => 'Служебная'])->slug))->assertOk()
        ->assertSee('Всё для дома')
        ->assertSee('Столы')
        ->assertDontSee('Скрытые стулья')
        ->assertDontSee('>Мебель<', false);
});

it('builds the footer: menu, contacts, social links, requisites and legal links (SF-02, SF-72)', function () {
    $general = app(GeneralSettings::class);
    $general->phones = ['+7 (999) 000-11-22'];
    $general->email = 'shop@example.com';
    $general->address = 'г. Тестовый, ул. Примерная, 1';
    $general->working_hours = 'Пн-Пт 9-18';
    $general->social_links = [['title' => 'Telegram', 'url' => 'https://t.me/shop'], ['title' => 'Bad', 'url' => 'javascript:alert(1)']];
    $general->save();

    $privacy = Page::factory()->create(['title' => 'Политика X']);
    $consent = Page::factory()->create(['title' => 'Согласие X']);
    $legal = app(LegalSettings::class);
    $legal->seller_details = "ИП Тестов Т.Т.\nИНН 000000000000\nОГРНИП 000000000000000";
    $legal->privacy_page_id = $privacy->id;
    $legal->consent_page_id = $consent->id;
    $legal->save();

    MenuItem::factory()->footer()->create(['label' => 'Пункт подвала', 'url' => '/catalog']);
    MenuItem::factory()->create(['label' => 'Пункт шапки']);

    $this->get('/')->assertOk()
        ->assertSee('Пункт подвала')
        ->assertSee('href="tel:+79990001122"', false)
        ->assertSee('href="mailto:shop@example.com"', false)
        ->assertSee('г. Тестовый, ул. Примерная, 1')
        ->assertSee('Пн-Пт 9-18')
        ->assertSee('https://t.me/shop')
        ->assertDontSee('javascript:alert(1)', false)
        ->assertSee('ИНН 000000000000')
        ->assertSee('ОГРНИП 000000000000000')
        ->assertSee(route('page.show', $privacy->slug, false), false)
        ->assertSee(route('page.show', $consent->slug, false), false)
        ->assertSee('© '.now()->year);
});

it('hides legal links of inactive pages and refreshes the cached menus on changes (NF-04)', function () {
    $legal = app(LegalSettings::class);
    $page = Page::find($legal->privacy_page_id);
    expect($page->is_active)->toBeTrue(); // D-058: shipped active
    $page->update(['is_active' => false]);

    $this->get('/')->assertOk()->assertDontSee(route('page.show', $page->slug, false), false);

    $page->update(['is_active' => true]);
    $this->get('/')->assertOk()->assertSee(route('page.show', $page->slug, false), false);

    $item = MenuItem::factory()->create(['label' => 'Кэш-пункт']);
    $this->get('/')->assertSee('Кэш-пункт');
    $item->update(['label' => 'Новая подпись']);
    $this->get('/')->assertDontSee('Кэш-пункт')->assertSee('Новая подпись');

    $category = Category::factory()->create(['name' => 'Была']);
    MenuItem::factory()->forCategory($category)->create(['label' => 'Раздел']);
    $this->get('/')->assertSee('Раздел');
    $category->update(['is_active' => false]);
    $this->get('/')->assertDontSee('Раздел');
});

it('links the consent checkbox to the active consent page (SF-71)', function () {
    $consent = Page::find(app(LegalSettings::class)->consent_page_id);
    $product = sellable(['sales_mode' => SalesMode::Inquiry, 'price_on_request' => true]);
    $consent->update(['is_active' => false]);

    // Inactive consent page: the text stays, the link is not shown.
    livewireWithCart(InquiryForm::class, null, ['productId' => $product->id])
        ->assertDontSee(route('page.show', $consent->slug, false), false);

    $consent->update(['is_active' => true]);

    livewireWithCart(InquiryForm::class, null, ['productId' => $product->id])
        ->assertSee(route('page.show', $consent->slug, false), false);
});

it('keeps the storefront query count low with menus (NF-04)', function () {
    $root = Category::factory()->create();
    Category::factory()->childOf($root)->create();
    MenuItem::factory()->forCategory($root)->create();
    MenuItem::factory()->footer()->forPage(Page::factory()->create())->create();

    $this->get('/')->assertOk();

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get('/')->assertOk();
    $warm = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($warm)->toBeLessThanOrEqual(30);
});
