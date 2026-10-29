<?php

declare(strict_types=1);

use App\Exceptions\CustomerGroupException;
use App\Filament\Resources\CustomerGroups\CustomerGroupResource;
use App\Filament\Resources\CustomerGroups\Pages\EditCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\ListCustomerGroups;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\Page;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Access\CustomerGroupService;
use App\Services\Access\ViewerContext;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * CG-25 (TZ 25.2): deleting a customer group.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->service = app(CustomerGroupService::class);
    $this->group = CustomerGroup::factory()->create(['name' => 'Дилеры', 'code' => 'dealers']);
});

describe('service', function (): void {
    it('refuses the system groups', function (): void {
        expect(fn () => $this->service->delete(CustomerGroup::retail()))->toThrow(CustomerGroupException::class, __('customer_groups.errors.system_group'))
            ->and(fn () => $this->service->delete(guestGroup()))->toThrow(CustomerGroupException::class);
        expect(CustomerGroup::query()->where('code', 'retail')->exists())->toBeTrue();
    });

    it('refuses the group new customers get', function (): void {
        $this->service->setDefaultForNew($this->group);

        expect(fn () => $this->service->delete($this->group))->toThrow(CustomerGroupException::class, __('customer_groups.errors.default_group'));
        expect(CustomerGroup::query()->whereKey($this->group->id)->exists())->toBeTrue();
    });

    it('moves the customers to «Розница» by default', function (): void {
        $customer = Customer::factory()->inGroup($this->group)->create();

        $this->service->delete($this->group);

        expect($customer->fresh()->customer_group_id)->toBe(CustomerGroup::retail()->id)
            ->and(CustomerGroup::query()->whereKey($this->group->id)->exists())->toBeFalse();
    });

    it('moves the customers to the chosen group', function (): void {
        $target = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
        $customer = Customer::factory()->inGroup($this->group)->create();
        $other = makeCustomer();

        $this->service->delete($this->group, $target);

        expect($customer->fresh()->customer_group_id)->toBe($target->id)
            ->and($other->fresh()->customer_group_id)->toBe(CustomerGroup::retail()->id);
    });

    it('refuses «Гость», an inactive group and the group itself as the target and changes nothing', function (): void {
        $customer = Customer::factory()->inGroup($this->group)->create();
        $inactive = CustomerGroup::factory()->create(['is_active' => false]);

        foreach ([guestGroup(), $inactive, $this->group] as $target) {
            expect(fn () => $this->service->delete($this->group, $target))->toThrow(CustomerGroupException::class, __('customer_groups.errors.target_invalid'));
        }

        expect(CustomerGroup::query()->whereKey($this->group->id)->exists())->toBeTrue()
            ->and($customer->fresh()->customer_group_id)->toBe($this->group->id);
    });

    it('removes the id from the visible groups of pages and menu items', function (): void {
        $keep = CustomerGroup::factory()->create();
        $page = Page::factory()->create(['visible_group_ids' => [$keep->id, $this->group->id]]);
        $item = MenuItem::factory()->create(['visible_group_ids' => [$this->group->id, $keep->id]]);
        $everyone = Page::factory()->create(['visible_group_ids' => null]);

        $this->service->delete($this->group);

        expect($page->fresh()->visible_group_ids)->toBe([$keep->id])
            ->and($item->fresh()->visible_group_ids)->toBe([$keep->id])
            ->and($everyone->fresh()->visible_group_ids)->toBeNull();
    });

    it('removes the category selection of the group', function (): void {
        $category = Category::factory()->create();
        $this->group->categories()->sync([$category->id]);

        $this->service->delete($this->group);

        expect(DB::table('customer_group_categories')->where('customer_group_id', $this->group->id)->count())->toBe(0)
            ->and(Category::query()->whereKey($category->id)->exists())->toBeTrue();
    });

    it('keeps the name snapshot of the orders and the decided applications', function (): void {
        $customer = Customer::factory()->inGroup($this->group)->create();
        $order = Order::factory()->create(['customer_id' => $customer->id, 'customer_group_id' => $this->group->id, 'customer_group_name' => 'Дилеры']);
        $application = WholesaleApplication::factory()->approved($this->group)->forCustomer($customer)->create();

        $this->service->delete($this->group);

        $order->refresh();
        expect($order->customer_group_name)->toBe('Дилеры')
            ->and($order->customer_id)->toBe($customer->id);
        expect($application->fresh()->decided_group_id)->toBeNull()
            ->and($application->fresh()->status->value)->toBe('approved');
    });

    it('makes the viewer of a moved customer «Розница»', function (): void {
        $customer = Customer::factory()->inGroup($this->group)->create();
        $this->service->delete($this->group);

        signInCustomer($this, $customer->fresh());
        $this->get('/');

        expect(app(ViewerContext::class)->group()->code)->toBe('retail');
    });
});

describe('admin', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('hides the delete action of the system groups', function (): void {
        Livewire::test(ListCustomerGroups::class)
            ->assertTableActionHidden('delete', CustomerGroup::retail())
            ->assertTableActionHidden('delete', guestGroup())
            ->assertTableActionVisible('delete', $this->group);
    });

    it('preselects «Розница», offers other active groups except «Гость» and itself', function (): void {
        Livewire::test(ListCustomerGroups::class)
            ->mountAction(TestAction::make('delete')->table($this->group))
            ->assertSchemaStateSet(['move_to' => CustomerGroup::retail()->id]);

        $targets = CustomerGroupResource::moveTargets($this->group);

        expect($targets)->toHaveKey(CustomerGroup::retail()->id)
            ->and($targets)->not->toHaveKey(guestGroup()->id)
            ->and($targets)->not->toHaveKey($this->group->id);
    });

    it('requires the target group', function (): void {
        Livewire::test(ListCustomerGroups::class)
            ->callAction(TestAction::make('delete')->table($this->group), ['move_to' => null])
            ->assertHasActionErrors(['move_to' => 'required']);

        expect(CustomerGroup::query()->whereKey($this->group->id)->exists())->toBeTrue();
    });

    it('deletes the group and moves the customers to the chosen one', function (): void {
        $target = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
        $customer = Customer::factory()->inGroup($this->group)->create();

        Livewire::test(ListCustomerGroups::class)
            ->callAction(TestAction::make('delete')->table($this->group), ['move_to' => $target->id]);

        expect(CustomerGroup::query()->whereKey($this->group->id)->exists())->toBeFalse()
            ->and($customer->fresh()->customer_group_id)->toBe($target->id);
    });

    it('deletes from the edit page too', function (): void {
        $customer = Customer::factory()->inGroup($this->group)->create();

        Livewire::test(EditCustomerGroup::class, ['record' => $this->group->getRouteKey()])
            ->callAction('delete', ['move_to' => CustomerGroup::retail()->id]);

        expect(CustomerGroup::query()->whereKey($this->group->id)->exists())->toBeFalse()
            ->and($customer->fresh()->customer_group_id)->toBe(CustomerGroup::retail()->id);
    });

    it('shows the service refusal for the group of new customers and keeps the group', function (): void {
        $this->service->setDefaultForNew($this->group);

        Livewire::test(ListCustomerGroups::class)
            ->callAction(TestAction::make('delete')->table($this->group), ['move_to' => CustomerGroup::retail()->id])
            ->assertNotified(__('customer_groups.errors.default_group'));

        expect(CustomerGroup::query()->whereKey($this->group->id)->exists())->toBeTrue();
    });
});
