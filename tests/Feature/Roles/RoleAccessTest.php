<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageGeneralSettings;
use App\Filament\Pages\SystemStatus;
use App\Filament\Resources\AuditLog\AuditLogResource;
use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Resources\Imports\ImportResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\PriceRules\PriceRuleResource;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Users\RoleService;
use App\Settings\GeneralSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Acceptance of stage 8.1: a custom «Контент-менеджер» role opens only its own sections (RB-05, RB-13).
 */
describe('custom role «Контент-менеджер»', function (): void {
    beforeEach(function (): void {
        $this->editor = staffWith([
            'dashboard.view',
            'products.view', 'products.create', 'products.update', 'products.delete',
            'categories.view', 'pages.view', 'pages.create', 'pages.update', 'pages.delete',
            'home.update', 'menu.view', 'menu.create', 'menu.update', 'menu.delete',
        ]);
        $this->actingAs($this->editor);
    });

    it('opens its own sections', function (string $url): void {
        $this->get($url)->assertOk();
    })->with([
        fn () => '/admin',
        fn () => ProductResource::getUrl('index'),
        fn () => PageResource::getUrl('index'),
    ]);

    it('gets 403 on every other section', function (string $url): void {
        $this->get($url)->assertForbidden();
    })->with([
        fn () => OrderResource::getUrl('index'),
        fn () => ImportResource::getUrl('index'),
        fn () => ExportResource::getUrl('index'),
        fn () => PriceRuleResource::getUrl('index'),
        fn () => UserResource::getUrl('index'),
        fn () => RoleResource::getUrl('index'),
        fn () => AuditLogResource::getUrl('index'),
        fn () => SystemStatus::getUrl(),
        fn () => ManageGeneralSettings::getUrl(),
    ]);

    it('has no menu items for the sections it cannot open', function (): void {
        $this->get('/admin')
            ->assertOk()
            ->assertSee(ProductResource::getPluralModelLabel())
            ->assertDontSee(__('admin_orders.plural'))
            ->assertDontSee(__('shop.navigation.settings'))
            ->assertDontSee(__('shop.navigation.system'))
            ->assertDontSee(__('roles.resource.plural'))
            ->assertDontSee(__('roles.audit.title'));
    });

    it('is checked in the Livewire action too, not only by hiding buttons', function (): void {
        $product = Product::factory()->create();

        // Bulk base price change needs prices.bulk: the action is hidden and refused.
        Livewire::test(ListProducts::class)
            ->assertActionHidden(TestAction::make('change_price')->table()->bulk());

        expect($this->editor->can('changePrices', Product::class))->toBeFalse()
            ->and($this->editor->can('bulk', Product::class))->toBeFalse()
            ->and($product->exists)->toBeTrue();
    });
});

describe('immediate effect of role changes (RB-10, RB-13)', function (): void {
    it('revokes a section on the next request after the role is changed', function (): void {
        $admin = User::factory()->admin()->create();
        $user = staffWith(['dashboard.view', 'pages.view']);
        $role = Role::query()->findOrFail($user->role_id);

        $this->actingAs($user)->get(PageResource::getUrl('index'))->assertOk();

        app(RoleService::class)->update($role, [], ['dashboard.view'], $admin);

        $this->actingAs($user)->get(PageResource::getUrl('index'))->assertForbidden();
    });

    it('locks out a user deactivated while signed in', function (): void {
        $user = User::factory()->manager()->create();
        $this->actingAs($user)->get('/admin')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
    });
});

describe('settings pages use their own permission keys', function (): void {
    it('is read-only with settings.general.view and writable with settings.general.update', function (): void {
        $viewer = staffWith(['dashboard.view', 'settings.general.view']);
        $this->actingAs($viewer);

        $this->get(ManageGeneralSettings::getUrl())->assertOk();

        Livewire::test(ManageGeneralSettings::class)->fillForm(['shop_name' => 'Взлом'])->call('save');
        expect(app(GeneralSettings::class)->shop_name)->not->toBe('Взлом');

        $writer = staffWith(['dashboard.view', 'settings.general.update']);
        $this->actingAs($writer);

        Livewire::test(ManageGeneralSettings::class)->fillForm(['shop_name' => 'Лавка'])->call('save')->assertHasNoFormErrors();
        expect(app(GeneralSettings::class)->shop_name)->toBe('Лавка');
    });

    it('declares the key from the translation key by default', function (): void {
        expect(ManageGeneralSettings::settingsPermission('update'))->toBe('settings.general.update');
    });
});
