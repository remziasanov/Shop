<?php

declare(strict_types=1);

use App\Enums\PromoLinkTargetType;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings\ManageSalesSettings;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\PromoLinks\Pages\CreatePromoLink;
use App\Filament\Resources\PromoLinks\Pages\EditPromoLink;
use App\Filament\Resources\PromoLinks\Pages\ListPromoLinks;
use App\Filament\Resources\PromoLinks\Pages\ViewPromoLink;
use App\Filament\Widgets\PromoTopWidget;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\Promo\PromoLinkStats;
use App\Settings\PromoSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoLinkForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Куртка в рекламе',
        'slug' => 'kurtka',
        'target_type' => 'url',
        'target_url' => '/catalog',
        'is_active' => true,
    ], $overrides);
}

// ------------------------------------------------------------------ permissions

it('shows the link list to a manager read-only and hides it without the view right (22.4)', function (): void {
    $link = PromoLink::factory()->create();

    actingAs(staffWith(['dashboard.view']));
    get('/admin/promo-links')->assertForbidden();

    actingAs(User::factory()->manager()->create());
    Livewire::test(ListPromoLinks::class)->assertOk()->assertCanSeeTableRecords([$link])->assertActionHidden('create');
    get('/admin/promo-links/create')->assertForbidden();
    Livewire::test(ViewPromoLink::class, ['record' => $link->id])->assertOk()->assertActionHidden('edit');
});

// ------------------------------------------------------------------ form (PM-21, PM-22, PM-27)

it('creates links of every destination type (PM-21)', function (): void {
    $admin = User::factory()->admin()->create();
    actingAs($admin);
    $product = Product::factory()->create();
    $category = Category::factory()->create(['slug' => 'shoes']);
    $code = PromoCode::factory()->create();

    Livewire::test(CreatePromoLink::class)
        ->fillForm(promoLinkForm(['slug' => 'product-link', 'target_type' => 'product', 'product_id' => $product->id, 'promo_code_id' => $code->id, 'utm_source' => 'vk', 'utm_campaign' => 'spring-26']))
        ->call('create')->assertHasNoFormErrors();
    Livewire::test(CreatePromoLink::class)
        ->fillForm(promoLinkForm(['slug' => 'category-link', 'target_type' => 'category', 'category_id' => $category->id]))
        ->call('create')->assertHasNoFormErrors();
    Livewire::test(CreatePromoLink::class)
        ->fillForm(promoLinkForm(['slug' => 'filter-link', 'target_type' => 'catalog_filter', 'filter_category_id' => $category->id, 'filter_query' => 'sort=price_asc']))
        ->call('create')->assertHasNoFormErrors();
    Livewire::test(CreatePromoLink::class)
        ->fillForm(promoLinkForm(['slug' => 'url-link', 'target_url' => '/pages/delivery']))
        ->call('create')->assertHasNoFormErrors();

    $byslug = fn (string $slug): PromoLink => PromoLink::query()->where('slug', $slug)->firstOrFail();

    expect($byslug('product-link')->target_type)->toBe(PromoLinkTargetType::Product)
        ->and($byslug('product-link')->target_id)->toBe($product->id)
        ->and($byslug('product-link')->promo_code_id)->toBe($code->id)
        ->and($byslug('product-link')->utm_source)->toBe('vk')
        ->and($byslug('product-link')->created_by)->toBe($admin->id)
        ->and($byslug('category-link')->target_id)->toBe($category->id)
        ->and($byslug('filter-link')->target_url)->toBe('/catalog/shoes?sort=price_asc')
        ->and($byslug('url-link')->target_url)->toBe('/pages/delivery')
        ->and($byslug('url-link')->target_id)->toBeNull();

    Livewire::test(EditPromoLink::class, ['record' => $byslug('filter-link')->id])
        ->assertFormSet(['target_type' => 'catalog_filter', 'filter_category_id' => $category->id, 'filter_query' => 'sort=price_asc']);
});

it('validates the slug format and uniqueness (PM-21)', function (): void {
    actingAs(User::factory()->admin()->create());
    PromoLink::factory()->create(['slug' => 'taken']);

    foreach (['ab', 'UPPER', 'with space', 'под', 'a_b_c', str_repeat('a', 65), 'taken'] as $bad) {
        Livewire::test(CreatePromoLink::class)
            ->fillForm(promoLinkForm(['slug' => $bad]))->call('create')
            ->assertHasFormErrors(['slug']);
    }

    Livewire::test(CreatePromoLink::class)->fillForm(promoLinkForm(['slug' => 'ok-slug-1']))->call('create')->assertHasNoFormErrors();
});

it('lets a link keep its own slug when edited (PM-21)', function (): void {
    actingAs(User::factory()->admin()->create());
    $link = PromoLink::factory()->create(['slug' => 'mine']);

    Livewire::test(EditPromoLink::class, ['record' => $link->id])
        ->fillForm(['name' => 'Новое имя'])->call('save')->assertHasNoFormErrors();

    expect($link->fresh()->name)->toBe('Новое имя')->and($link->fresh()->slug)->toBe('mine');
});

it('accepts only internal addresses for url destinations (PM-22)', function (): void {
    actingAs(User::factory()->admin()->create());

    foreach (['https://evil.example/x', '//evil.example', '/\\evil.example', 'javascript:alert(1)', 'catalog', '/with space', ''] as $i => $bad) {
        Livewire::test(CreatePromoLink::class)
            ->fillForm(promoLinkForm(['slug' => 'bad-url-'.$i, 'target_url' => $bad]))->call('create')
            ->assertHasFormErrors(['target_url']);
    }

    expect(PromoLink::query()->count())->toBe(0);
});

it('requires the target of the chosen type and a valid UTM value (PM-21, PM-27)', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(CreatePromoLink::class)
        ->fillForm(promoLinkForm(['target_type' => 'product', 'product_id' => null]))->call('create')
        ->assertHasFormErrors(['product_id']);
    Livewire::test(CreatePromoLink::class)
        ->fillForm(promoLinkForm(['target_type' => 'category', 'category_id' => null]))->call('create')
        ->assertHasFormErrors(['category_id']);
    Livewire::test(CreatePromoLink::class)
        ->fillForm(promoLinkForm(['utm_source' => 'bad value!']))->call('create')
        ->assertHasFormErrors(['utm_source']);
    Livewire::test(CreatePromoLink::class)
        ->fillForm(promoLinkForm(['starts_at' => '2026-06-10 10:00:00', 'ends_at' => '2026-06-01 10:00:00']))->call('create')
        ->assertHasFormErrors(['ends_at']);
});

// ------------------------------------------------------------------ actions (PM-26)

it('checks the destination of a link and explains why it does not work (PM-26)', function (): void {
    actingAs(User::factory()->admin()->create());
    $ok = PromoLink::factory()->toUrl('/pages/delivery')->create(['utm_source' => 'vk']);
    $off = PromoLink::factory()->inactive()->create();
    $expired = PromoLink::factory()->create(['ends_at' => now()->subDay()]);

    Livewire::test(ListPromoLinks::class)
        ->callAction(TestAction::make('check')->table($ok))
        ->assertNotified(__('promo_admin.links.actions.check_ok', ['url' => '/pages/delivery?utm_source=vk']));

    Livewire::test(ListPromoLinks::class)
        ->callAction(TestAction::make('check')->table($off))
        ->assertNotified(__('promo_admin.links.actions.check_failed', ['reason' => __('promo.link.reasons.inactive')]));

    Livewire::test(ListPromoLinks::class)
        ->callAction(TestAction::make('check')->table($expired))
        ->assertNotified(__('promo_admin.links.actions.check_failed', ['reason' => __('promo.link.reasons.expired')]));
});

it('copies the full address and previews the final address in a new tab (PM-26)', function (): void {
    actingAs(User::factory()->admin()->create());
    config(['app.url' => 'https://shop.example']);
    $link = PromoLink::factory()->toUrl('/pages/delivery')->create(['slug' => 'delivery', 'utm_medium' => 'cpc']);
    $off = PromoLink::factory()->inactive()->create();

    Livewire::test(ListPromoLinks::class)
        ->callAction(TestAction::make('copy')->table($link))
        ->assertNotified(__('promo_admin.links.actions.copied'));

    Livewire::test(ListPromoLinks::class)
        ->assertActionHasUrl(TestAction::make('preview')->table($link), url('/pages/delivery?utm_medium=cpc'))
        ->assertActionShouldOpenUrlInNewTab(TestAction::make('preview')->table($link))
        ->callAction(TestAction::make('preview')->table($off))
        ->assertNotified(__('promo_admin.links.actions.preview_unavailable', ['reason' => __('promo.link.reasons.inactive')]));
});

it('renders the link card with the check, the code status and the statistics (PM-25, PM-26)', function (): void {
    actingAs(User::factory()->admin()->create());
    $code = PromoCode::factory()->create(['code' => 'SPRING10']);
    $link = PromoLink::factory()->toUrl('/catalog')->create(['promo_code_id' => $code->id, 'slug' => 'spring']);
    $stats = app(PromoLinkStats::class);
    $stats->recordClick($link, true, Carbon::now());
    $stats->recordClick($link, false, Carbon::now());
    Order::factory()->create(['promo_link_id' => $link->id, 'total' => '1500.00']);

    Livewire::test(ViewPromoLink::class, ['record' => $link->id])
        ->assertOk()
        ->assertSee('/go/spring')
        ->assertSee('SPRING10')
        ->assertSee(__('promo_admin.links.stats.conversion'))
        ->assertSee('50 %');

    $off = PromoLink::factory()->inactive()->create();
    Livewire::test(ViewPromoLink::class, ['record' => $off->id])->assertOk()
        ->assertSee(__('promo.link.reasons.inactive'))
        ->assertSee(__('promo_admin.links.code_status.none'));

    Livewire::test(ListPromoLinks::class)->assertCanSeeTableRecords([$link, $off])->assertSee('/go/spring');
});

// ------------------------------------------------------------------ order card (PM-19)

it('shows the promo block in the order card with links to the code and the link (PM-19)', function (): void {
    actingAs(User::factory()->admin()->create());
    $code = PromoCode::factory()->create(['code' => 'ORDERCODE']);
    $link = PromoLink::factory()->create(['name' => 'Рассылка', 'slug' => 'mailing']);
    $order = Order::factory()->create([
        'promo_code_id' => $code->id,
        'promo_code' => 'ORDERCODE',
        'promo_discount' => '150.00',
        'manual_discount' => '50.00',
        'promo_link_id' => $link->id,
    ]);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertOk()
        ->assertSee(__('promo_admin.order.section'))
        ->assertSee('ORDERCODE')
        ->assertSee('Рассылка (/go/mailing)')
        ->assertSee(route('filament.admin.resources.promo-codes.view', ['record' => $code]), false)
        ->assertSee(route('filament.admin.resources.promo-links.view', ['record' => $link]), false);
});

it('keeps the code snapshot when the code was deleted and hides the block for plain orders (PM-18, PM-19)', function (): void {
    actingAs(User::factory()->admin()->create());
    $order = Order::factory()->create(['promo_code_id' => null, 'promo_code' => 'GONE10', 'promo_discount' => '100.00']);
    $plain = Order::factory()->create();

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertSee('GONE10')->assertSee(__('promo_admin.order.code_deleted'));
    Livewire::test(ViewOrder::class, ['record' => $plain->getRouteKey()])->assertDontSee(__('promo_admin.order.section'));
});

it('does not link the code to a user without the promo view right (PM-19)', function (): void {
    actingAs(staffWith(['orders.view']));
    $code = PromoCode::factory()->create(['code' => 'NOLINK10']);
    $order = Order::factory()->create(['promo_code_id' => $code->id, 'promo_code' => 'NOLINK10', 'promo_discount' => '10.00']);

    Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
        ->assertSee('NOLINK10')
        ->assertDontSee(route('filament.admin.resources.promo-codes.view', ['record' => $code]), false);
});

// ------------------------------------------------------------------ dashboard widget (22.4)

it('renders the promo widget with the top codes and links of the last 30 days (22.4)', function (): void {
    actingAs(User::factory()->admin()->create());
    $code = PromoCode::factory()->create(['code' => 'TOPCODE']);
    PromoRedemption::factory()->count(2)->create(['promo_code_id' => $code->id, 'discount_amount' => '100.00']);
    $link = PromoLink::factory()->create(['name' => 'Топ ссылка']);
    app(PromoLinkStats::class)->recordClick($link, true);

    Livewire::test(PromoTopWidget::class)->assertOk()
        ->assertSee('TOPCODE')
        ->assertSee('Топ ссылка');

    Livewire::test(Dashboard::class)->assertSeeLivewire(PromoTopWidget::class);
});

it('shows the promo widget with the codes right only and hides links without their right (22.4)', function (): void {
    $link = PromoLink::factory()->create(['name' => 'Скрытая ссылка']);
    app(PromoLinkStats::class)->recordClick($link, true);

    actingAs(staffWith(['dashboard.view']));
    expect(PromoTopWidget::canView())->toBeFalse();

    actingAs(staffWith(['dashboard.view', 'promo_codes.view']));
    expect(PromoTopWidget::canView())->toBeTrue();
    Livewire::test(PromoTopWidget::class)->assertOk()->assertDontSee('Скрытая ссылка');
});

// ------------------------------------------------------------------ settings (PM-12)

it('keeps the release-on-cancel option on the sales settings page (PM-12)', function (): void {
    actingAs(User::factory()->admin()->create());
    app(PromoSettings::class)->fill(['release_on_cancel' => true])->save();

    Livewire::test(ManageSalesSettings::class)
        ->assertFormSet(['promo_release_on_cancel' => true])
        ->fillForm(['promo_release_on_cancel' => false])
        ->call('save')->assertHasNoFormErrors();

    expect(app(PromoSettings::class)->release_on_cancel)->toBeFalse();
});
