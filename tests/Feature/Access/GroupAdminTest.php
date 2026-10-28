<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Enums\PriceDisplayMode;
use App\Exceptions\CustomerGroupException;
use App\Filament\Pages\Settings\ManageSalesSettings;
use App\Filament\Resources\CustomerGroups\CustomerGroupResource;
use App\Filament\Resources\CustomerGroups\Pages\CreateCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\EditCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\ListCustomerGroups;
use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use App\Services\Access\AccessSet;
use App\Services\Access\CatalogAccess;
use App\Services\Access\CustomerGroupService;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CatalogVersion;
use App\Settings\SalesSettings;
use Livewire\Livewire;

/**
 * CG-01, CG-03, CG-10, CG-18, CG-25, RB-05 (TZ 25.9): the groups resource, its policy and the page / menu fields.
 */
describe('migration defaults', function (): void {
    it('creates the system groups «Гость» and «Розница» with v1.0 behaviour (CG-01, CG-03)', function (): void {
        $groups = CustomerGroup::query()->ordered()->get();

        expect($groups->pluck('code')->all())->toBe(['guest', 'retail'])
            ->and($groups->every(fn (CustomerGroup $g): bool => $g->is_system && $g->is_active))->toBeTrue();

        foreach ($groups as $group) {
            $access = app(CatalogAccess::class)->for($group);

            expect($access->restrictsCategories())->toBeFalse()
                ->and($access->showsPrices())->toBeTrue()
                ->and($access->allowedSalesModes)->toBeNull()
                ->and($access->allowedDeliveryCodes)->toBeNull()
                ->and($access->minOrderAmount)->toBeNull();
        }
    });

    it('keeps the code and the system flag of a system group (CG-01)', function (): void {
        $guest = guestGroup();
        $guest->update(['code' => 'changed', 'name' => 'Посетитель', 'is_active' => false]);

        expect($guest->fresh()->code)->toBe('guest')
            ->and($guest->fresh()->is_system)->toBeTrue()
            ->and($guest->fresh()->is_active)->toBeTrue()
            ->and($guest->fresh()->name)->toBe('Посетитель');
    });

    it('refuses to delete a system group but deletes another one (CG-01)', function (): void {
        $service = app(CustomerGroupService::class);

        expect(fn () => $service->delete(CustomerGroup::retail()))->toThrow(CustomerGroupException::class);
        expect(guestGroup()->delete())->toBeFalse();

        $other = CustomerGroup::factory()->create();
        $service->delete($other);
        expect(CustomerGroup::query()->whereKey($other->id)->exists())->toBeFalse();
    });

    it('drops a deleted group from visible_group_ids of pages and menu items (CG-25)', function (): void {
        $keep = CustomerGroup::factory()->create();
        $gone = CustomerGroup::factory()->create();
        $page = Page::factory()->create(['visible_group_ids' => [$keep->id, $gone->id]]);
        $item = MenuItem::factory()->create(['visible_group_ids' => [$gone->id]]);

        app(CustomerGroupService::class)->delete($gone);

        expect($page->fresh()->visible_group_ids)->toBe([$keep->id])
            ->and($item->fresh()->visible_group_ids)->toBe([]);
    });
});

describe('groups resource', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('lists the groups (CG-18)', function (): void {
        Livewire::test(ListCustomerGroups::class)->assertOk()->assertCanSeeTableRecords(CustomerGroup::query()->get());
    });

    it('creates a group with a category selection (CG-05, CG-18)', function (): void {
        $category = Category::factory()->create();

        Livewire::test(CreateCustomerGroup::class)
            ->fillForm([
                'name' => 'Опт',
                'code' => 'opt',
                'sort_order' => 20,
                'is_active' => true,
                'category_access_mode' => 'only',
                'category_ids' => [$category->id],
                'price_display' => 'request',
                'stock_display' => 'low',
                'min_order_amount' => '20000',
                'allowed_sales_modes' => ['cart', 'inquiry'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = CustomerGroup::query()->where('code', 'opt')->firstOrFail();

        expect($group->category_access_mode)->toBe(CategoryAccessMode::Only)
            ->and($group->price_display)->toBe(PriceDisplayMode::Request)
            ->and($group->categories()->pluck('categories.id')->all())->toBe([$category->id])
            ->and($group->allowed_sales_modes)->toBe(['cart', 'inquiry'])
            ->and($group->min_order_amount)->toBe('20000.00')
            ->and($group->is_system)->toBeFalse();
    });

    it('stores a fully checked list as «no restriction» (CG-07, CG-09)', function (): void {
        Livewire::test(CreateCustomerGroup::class)
            ->fillForm([
                'name' => 'Все',
                'code' => 'all-open',
                'category_access_mode' => 'all',
                'price_display' => 'show',
                'stock_display' => 'inherit',
                'allowed_sales_modes' => ['cart', 'reservation', 'inquiry'],
                'allowed_delivery_codes' => ['samovyvoz', 'kurerskaia-dostavka'],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $group = CustomerGroup::query()->where('code', 'all-open')->firstOrFail();

        expect($group->allowed_sales_modes)->toBeNull()->and($group->allowed_delivery_codes)->toBeNull();
    });

    it('validates the code and its uniqueness (CG-01)', function (): void {
        Livewire::test(CreateCustomerGroup::class)
            ->fillForm(['name' => 'X', 'code' => 'Bad Code'])
            ->call('create')
            ->assertHasFormErrors(['code']);

        Livewire::test(CreateCustomerGroup::class)
            ->fillForm(['name' => 'X', 'code' => 'retail'])
            ->call('create')
            ->assertHasFormErrors(['code']);
    });

    it('edits a system group without changing its code and bumps the catalog version (CG-01, CG-19)', function (): void {
        $guest = guestGroup();
        $before = CatalogVersion::current();

        Livewire::test(EditCustomerGroup::class, ['record' => $guest->getRouteKey()])
            ->assertFormFieldDisabled('code')
            ->fillForm(['price_display' => 'request'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($guest->fresh()->price_display)->toBe(PriceDisplayMode::Request)
            ->and($guest->fresh()->code)->toBe('guest')
            ->and(CatalogVersion::current())->not->toBe($before);
    });

    it('does not delete a system group from the table (CG-01)', function (): void {
        Livewire::test(ListCustomerGroups::class)
            ->assertTableActionHidden('delete', guestGroup());

        expect(CustomerGroupResource::deleteRecord(CustomerGroup::retail()))->toBeFalse();
    });

    it('offers the preview of the storefront as a group (CG-18)', function (): void {
        $group = CustomerGroup::factory()->create();

        Livewire::test(ListCustomerGroups::class)->assertTableActionExists('preview', null, $group);

        $this->get(route('viewer.preview', $group))->assertRedirect(route('home'));
        expect(session(ViewerContext::PREVIEW_KEY))->toBe($group->id);
        $this->get(route('home'))->assertOk()->assertSee($group->name);
        $this->get(route('viewer.preview.exit'))->assertRedirect(route('home'));
        expect(session(ViewerContext::PREVIEW_KEY))->toBeNull();
    });
});

describe('permissions', function (): void {
    it('lets a manager view the groups but not change them (RB-05, 6.13)', function (): void {
        $this->actingAs(User::factory()->manager()->create());
        $group = CustomerGroup::factory()->create();

        expect(CustomerGroupResource::canViewAny())->toBeTrue()
            ->and(CustomerGroupResource::canCreate())->toBeFalse()
            ->and(CustomerGroupResource::canEdit($group))->toBeFalse()
            ->and(CustomerGroupResource::canDelete($group))->toBeFalse();
    });

    it('follows the customer_groups.* permissions of the role (RB-05)', function (): void {
        $group = CustomerGroup::factory()->create();

        $this->actingAs(staffWith(['customer_groups.view', 'customer_groups.update']));
        expect(CustomerGroupResource::canViewAny())->toBeTrue()
            ->and(CustomerGroupResource::canEdit($group))->toBeTrue()
            ->and(CustomerGroupResource::canCreate())->toBeFalse()
            ->and(CustomerGroupResource::canDelete($group))->toBeFalse();

        $this->actingAs(staffWith(['customer_groups.view', 'customer_groups.delete']));
        expect(CustomerGroupResource::canDelete($group))->toBeTrue()
            ->and(CustomerGroupResource::canDelete(CustomerGroup::retail()))->toBeFalse();

        $this->actingAs(staffWith(['pages.view']));
        expect(CustomerGroupResource::canViewAny())->toBeFalse();
        $this->get(CustomerGroupResource::getUrl('index'))->assertForbidden();
    });

    it('refuses the storefront preview to staff without the right (CG-18)', function (): void {
        $group = CustomerGroup::factory()->create();

        $this->actingAs(staffWith(['pages.view']));
        $this->get(route('viewer.preview', $group))->assertForbidden();

        auth()->logout();
        $this->get(route('viewer.preview', $group))->assertForbidden();
    });

    it('ignores a preview flag in the session of a visitor who is not staff (CG-16)', function (): void {
        $group = CustomerGroup::factory()->create(['name' => 'Тайная группа']);

        $this->withSession([ViewerContext::PREVIEW_KEY => $group->id])->get('/')->assertOk()->assertDontSee('Тайная группа');
        expect(app(ViewerContext::class)->group()->code)->toBe('guest');
    });
});

describe('page and menu fields', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('saves «Показывать группам» of a page and treats an empty selection as everybody (CG-10)', function (): void {
        $group = CustomerGroup::factory()->create();

        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Для группы', 'is_active' => true, 'visible_group_ids' => [$group->id]])
            ->call('create')
            ->assertHasNoFormErrors();
        Livewire::test(CreatePage::class)
            ->fillForm(['title' => 'Для всех', 'is_active' => true, 'visible_group_ids' => []])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Page::query()->where('title', 'Для группы')->firstOrFail()->visible_group_ids)->toBe([$group->id])
            ->and(Page::query()->where('title', 'Для всех')->firstOrFail()->visible_group_ids)->toBeNull();
    });

    it('saves «Показывать группам» of a menu item (CG-10)', function (): void {
        $group = CustomerGroup::factory()->create();

        Livewire::test(CreateMenuItem::class)
            ->fillForm(['location' => 'header', 'label' => 'Для группы', 'link_type' => 'url', 'url' => '/x', 'sort_order' => 1, 'is_active' => true, 'visible_group_ids' => [$group->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(MenuItem::query()->where('label', 'Для группы')->firstOrFail()->visible_group_ids)->toBe([$group->id]);
    });

    it('keeps the immutable method codes when the sales settings are saved (CG-09)', function (): void {
        $page = Livewire::test(ManageSalesSettings::class);
        $page->call('save')->assertHasNoFormErrors();

        $settings = app(SalesSettings::class);
        $settings->refresh();

        expect(array_column($settings->delivery_methods, 'code'))->toBe(['samovyvoz', 'kurerskaia-dostavka'])
            ->and(array_column($settings->payment_methods, 'code'))->toBe(['nalicnymi-pri-polucenii', 'kartoi-pri-polucenii']);
    });
});

describe('access sets', function (): void {
    it('computes the sets of the three modes with subtrees and navigation parents (CG-05)', function (): void {
        $root = Category::factory()->create();
        $a = Category::factory()->childOf($root)->create();
        $a1 = Category::factory()->childOf($a)->create();
        $b = Category::factory()->childOf($root)->create();

        $only = app(CatalogAccess::class)->for(CustomerGroup::factory()->categoryAccess(CategoryAccessMode::Only, [$a])->create());
        $except = app(CatalogAccess::class)->for(CustomerGroup::factory()->categoryAccess(CategoryAccessMode::AllExcept, [$a])->create());

        expect($only->canSeeProductsIn($a->id))->toBeTrue()
            ->and($only->canSeeProductsIn($a1->id))->toBeTrue()
            ->and($only->canSeeProductsIn($root->id))->toBeFalse()
            ->and($only->canSeeCategory($root->id))->toBeTrue()
            ->and($only->canSeeCategory($b->id))->toBeFalse()
            ->and($except->canSeeProductsIn($a1->id))->toBeFalse()
            ->and($except->canSeeCategory($root->id))->toBeTrue()
            ->and($except->canSeeProductsIn($b->id))->toBeTrue();
    });

    it('survives a round trip through the cache (CG-19)', function (): void {
        $category = Category::factory()->create();
        $group = CustomerGroup::factory()->priceDisplay(PriceDisplayMode::Login)->salesModes(['inquiry'])
            ->categoryAccess(CategoryAccessMode::Only, [$category])->create(['min_order_amount' => '150.50', 'allowed_delivery_codes' => ['samovyvoz']]);

        $fresh = app(CatalogAccess::class)->for($group);
        $copy = AccessSet::fromArray($fresh->toArray());

        expect($copy->toArray())->toBe($fresh->toArray())
            ->and($copy->minOrderAmount)->toBe(15050)
            ->and($copy->allowsDelivery('samovyvoz'))->toBeTrue()
            ->and($copy->allowsDelivery('kurerskaia-dostavka'))->toBeFalse();
    });
});
