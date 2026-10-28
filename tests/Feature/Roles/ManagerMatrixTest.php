<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Export;
use App\Models\Import;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Page;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;
use App\Services\Users\PermissionService;

/**
 * RB-06, RB-15: after the migration a manager can do exactly what the v1.0 matrix (TZ 6.13) allowed.
 */
describe('manager keeps the v1.0 matrix', function (): void {
    beforeEach(function (): void {
        $this->manager = User::factory()->manager()->create();
        $this->admin = User::factory()->admin()->create();
    });

    it('is allowed what the matrix gives managers', function (string $ability, string $model): void {
        expect($this->manager->can($ability, $model))->toBeTrue();
    })->with([
        ['viewAny', Product::class], ['create', Product::class], ['bulk', Product::class],
        ['viewAny', Category::class], ['create', Category::class],
        ['viewAny', Page::class], ['create', Page::class],
        ['viewAny', MenuItem::class], ['create', MenuItem::class],
        ['viewAny', Order::class], ['export', Order::class],
        ['viewAny', Import::class], ['create', Import::class],
        ['viewAny', Export::class], ['create', Export::class],
        ['viewAny', PriceHistory::class], ['viewAny', PriceRule::class],
    ]);

    it('is refused what the matrix keeps for administrators', function (string $ability, string $model): void {
        expect($this->manager->can($ability, $model))->toBeFalse();
    })->with([
        ['changePrices', Product::class],
        ['create', PriceRule::class],
        ['deleteAny', Order::class],
        ['useFullSync', Import::class],
        ['viewAny', User::class],
    ]);

    it('manages categories, pages and menu items fully', function (): void {
        foreach ([Category::factory()->create(), Page::factory()->create(), MenuItem::factory()->create()] as $record) {
            expect($this->manager->can('update', $record))->toBeTrue()
                ->and($this->manager->can('delete', $record))->toBeTrue();
        }
    });

    it('cannot edit or delete price rules, orders or imports, but admins can', function (): void {
        $rule = PriceRule::factory()->create();
        $order = Order::factory()->create();

        expect($this->manager->can('update', $rule))->toBeFalse()
            ->and($this->manager->can('delete', $rule))->toBeFalse()
            ->and($this->manager->can('delete', $order))->toBeFalse()
            ->and($this->manager->can('update', $order))->toBeTrue()
            ->and($this->admin->can('update', $rule))->toBeTrue()
            ->and($this->admin->can('delete', $order))->toBeTrue();
    });

    it('has no settings, user, role or system rights and cannot edit the home HTML block', function (): void {
        $permissions = app(PermissionService::class);

        foreach (['settings.general.view', 'settings.general.update', 'settings.seo.update', 'users.view', 'roles.view', 'system.view', 'system.audit_log.view', 'html_block.edit', 'prices.bulk', 'imports.full_sync', 'orders.delete', 'orders.edit_prices', 'price_rules.update'] as $permission) {
            expect($permissions->allows($this->manager, $permission))->toBeFalse($permission);
        }

        foreach (['dashboard.view', 'home.update', 'price_history.view', 'orders.export', 'imports.run'] as $permission) {
            expect($permissions->allows($this->manager, $permission))->toBeTrue($permission);
        }
    });
});
