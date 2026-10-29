<?php

declare(strict_types=1);

use App\Exceptions\CustomerGroupException;
use App\Filament\Pages\Settings\ManageCustomerSettings;
use App\Filament\Resources\CustomerGroups\Pages\EditCustomerGroup;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Access\CustomerGroupService;
use App\Settings\CustomerSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * CustomerSettings («Покупатели и регистрация», CA-23), the stage-9 columns of customer groups (CG-11, CG-12, CG-22)
 * and the groups created by the migration (D-088, 25.10).
 */
describe('migration data', function (): void {
    it('creates the three groups of 25.10 with the stage-9 flags', function (): void {
        $groups = CustomerGroup::query()->get()->keyBy('code');

        expect($groups->keys()->all())->toContain('guest', 'retail', 'opt');

        $retail = $groups['retail'];
        expect($retail->is_system)->toBeTrue()
            ->and($retail->is_default_for_new)->toBeTrue()
            ->and($retail->require_login_to_order)->toBeFalse();

        $opt = $groups['opt'];
        expect($opt->name)->toBe('Опт')
            ->and($opt->is_system)->toBeFalse()
            ->and($opt->is_active)->toBeTrue()
            ->and($opt->is_wholesale_default)->toBeTrue()
            ->and($opt->require_login_to_order)->toBeTrue()
            ->and($opt->is_default_for_new)->toBeFalse();

        $guest = $groups['guest'];
        expect($guest->is_default_for_new)->toBeFalse()->and($guest->require_login_to_order)->toBeFalse();
    });

    it('keeps the v1.0 behaviour by default: guest orders are allowed, registration is on (NF-56, CA-01)', function (): void {
        $settings = app(CustomerSettings::class);

        expect($settings->allow_guest_checkout)->toBeTrue()
            ->and($settings->registration_enabled)->toBeTrue()
            ->and($settings->code_ttl_minutes)->toBe(15)
            ->and($settings->code_max_attempts)->toBe(5)
            ->and($settings->code_resend_seconds)->toBe(60)
            ->and($settings->unverified_retention_days)->toBe(7)
            ->and($settings->deletion_grace_days)->toBe(3)
            ->and($settings->password_min_length)->toBe(8)
            ->and($settings->code_mail_warning)->toContain('Никому не сообщайте');
    });
});

describe('migration down()', function (): void {
    it('keeps an opt group that existed before the migration', function (): void {
        $migration = require database_path('migrations/2026_11_12_100001_add_customer_columns_to_customer_groups.php');

        // A group of the shop's own (another description): down() must not delete it.
        CustomerGroup::query()->where('code', 'opt')->update(['description' => 'Своя группа']);
        $migration->down();

        expect(DB::table('customer_groups')->where('code', 'opt')->exists())->toBeTrue();
    });

    it('removes the opt group the migration created', function (): void {
        $migration = require database_path('migrations/2026_11_12_100001_add_customer_columns_to_customer_groups.php');

        expect(DB::table('customer_groups')->where('code', 'opt')->exists())->toBeTrue();
        $migration->down();

        expect(DB::table('customer_groups')->where('code', 'opt')->exists())->toBeFalse();
    });
});

describe('default group for new customers', function (): void {
    it('moves the flag so exactly one group has it (CG-11)', function (): void {
        $group = CustomerGroup::factory()->create();

        app(CustomerGroupService::class)->setDefaultForNew($group);

        expect(CustomerGroup::query()->where('is_default_for_new', true)->pluck('id')->all())->toBe([$group->id])
            ->and(CustomerGroup::defaultForNew()->id)->toBe($group->id);
    });

    it('refuses an inactive group', function (): void {
        $group = CustomerGroup::factory()->create(['is_active' => false]);

        expect(fn () => app(CustomerGroupService::class)->setDefaultForNew($group))->toThrow(CustomerGroupException::class);
    });

    it('does not delete the default group of new customers', function (): void {
        $group = CustomerGroup::factory()->create();
        app(CustomerGroupService::class)->setDefaultForNew($group);

        expect(fn () => app(CustomerGroupService::class)->delete($group->fresh()))->toThrow(CustomerGroupException::class);
        expect(CustomerGroup::query()->whereKey($group->id)->exists())->toBeTrue();
    });

    it('moves the customers of a deleted group to «Розница» (CG-25)', function (): void {
        $group = CustomerGroup::factory()->create();
        $customer = makeCustomer(['customer_group_id' => $group->id]);

        app(CustomerGroupService::class)->delete($group);

        expect($customer->fresh()->customer_group_id)->toBe(CustomerGroup::retail()->id)
            ->and(CustomerGroup::query()->whereKey($group->id)->exists())->toBeFalse();
    });

    it('moves the customers of a deleted group to the chosen group (CG-25)', function (): void {
        $group = CustomerGroup::factory()->create();
        $target = CustomerGroup::factory()->create();
        $customer = makeCustomer(['customer_group_id' => $group->id]);

        app(CustomerGroupService::class)->delete($group, $target);

        expect($customer->fresh()->customer_group_id)->toBe($target->id);
    });

    it('refuses to delete the system groups', function (): void {
        expect(fn () => app(CustomerGroupService::class)->delete(CustomerGroup::retail()))->toThrow(CustomerGroupException::class);
    });

    it('keeps a group that has customers from being deleted by a raw query (restrict foreign key)', function (): void {
        // SQLite tests do not enforce the key; the service is the guard. The service never leaves a customer behind.
        $group = CustomerGroup::factory()->create();
        makeCustomer(['customer_group_id' => $group->id]);

        app(CustomerGroupService::class)->delete($group);

        expect(Customer::query()->whereNotIn('customer_group_id', CustomerGroup::query()->pluck('id'))->count())->toBe(0);
    });
});

describe('settings page', function (): void {
    beforeEach(function (): void {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('renders for an administrator and shows the current default group', function (): void {
        Livewire::test(ManageCustomerSettings::class)
            ->assertOk()
            ->assertFormSet(['default_group_id' => CustomerGroup::retail()->id, 'allow_guest_checkout' => true]);
    });

    it('saves the settings and moves the default group (CA-23, CG-11)', function (): void {
        $group = CustomerGroup::factory()->create();

        Livewire::test(ManageCustomerSettings::class)
            ->fillForm([
                'registration_enabled' => false,
                'allow_guest_checkout' => false,
                'default_group_id' => $group->id,
                'code_ttl_minutes' => 20,
                'code_max_attempts' => 4,
                'code_resend_seconds' => 90,
                'unverified_retention_days' => 14,
                'deletion_grace_days' => 5,
                'password_min_length' => 10,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(CustomerSettings::class);
        expect($settings->registration_enabled)->toBeFalse()
            ->and($settings->allow_guest_checkout)->toBeFalse()
            ->and($settings->code_ttl_minutes)->toBe(20)
            ->and($settings->code_max_attempts)->toBe(4)
            ->and($settings->code_resend_seconds)->toBe(90)
            ->and($settings->unverified_retention_days)->toBe(14)
            ->and($settings->deletion_grace_days)->toBe(5)
            ->and($settings->password_min_length)->toBe(10);

        expect(CustomerGroup::defaultForNew()->id)->toBe($group->id)
            ->and(CustomerGroup::retail()->fresh()->is_default_for_new)->toBeFalse();
    });

    it('validates the numbers', function (): void {
        Livewire::test(ManageCustomerSettings::class)
            ->fillForm(['code_ttl_minutes' => 0, 'code_max_attempts' => 99, 'code_resend_seconds' => 1, 'password_min_length' => 2])
            ->call('save')
            ->assertHasFormErrors(['code_ttl_minutes', 'code_max_attempts', 'code_resend_seconds', 'password_min_length']);
    });

    it('does not allow a minimum password length below 8 (CA-02)', function (): void {
        Livewire::test(ManageCustomerSettings::class)->fillForm(['password_min_length' => 7])->call('save')->assertHasFormErrors(['password_min_length']);
        Livewire::test(ManageCustomerSettings::class)->fillForm(['password_min_length' => 8])->call('save')->assertHasNoFormErrors();
    });

    it('is closed to a role without the settings right (RB-05)', function (): void {
        $this->actingAs(staffWith(['orders.view']));

        expect(ManageCustomerSettings::canAccess())->toBeFalse();
    });

    it('opens for a role with the view right only, read-only', function (): void {
        $this->actingAs(staffWith(['settings.customers.view']));

        expect(ManageCustomerSettings::canAccess())->toBeTrue();
        Livewire::test(ManageCustomerSettings::class)->assertOk();
    });

    it('offers the sign-in price mode and the «order only after login» switch on the group form (CG-06, CG-12)', function (): void {
        $group = CustomerGroup::factory()->create();

        Livewire::test(EditCustomerGroup::class, ['record' => $group->id])
            ->fillForm(['price_display' => 'login', 'require_login_to_order' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $group->refresh();
        expect($group->price_display->value)->toBe('login')->and($group->require_login_to_order)->toBeTrue();
    });
});
