<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAccountException;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Customers\RelationManagers\OrdersRelationManager;
use App\Filament\Widgets\NewCustomersWidget;
use App\Models\AdminAuditEntry;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerCompany;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\User;
use App\Services\Customers\CustomerAdminService;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * CA-20, CA-21, CA-23 (TZ 17.3, 17.4): the «Покупатели» resource, its actions, permissions and the dashboard widget.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->admin = User::factory()->admin()->create();
});

function wholesaleGroup(): CustomerGroup
{
    return CustomerGroup::factory()->create(['name' => 'Оптовики']);
}

it('lists customers with search by name, e-mail and phone digits, and the filters (CA-20)', function (): void {
    actingAs($this->admin);
    $ivan = makeCustomer(['name' => 'Иван Уникальный', 'email' => 'ivan.u@example.com', 'phone' => '+79121234567']);
    $petr = makeCustomer(['name' => 'Пётр Другой', 'email' => 'petr.d@example.com', 'phone' => '+79997654321', 'email_verified_at' => null, 'is_blocked' => true]);
    $opt = makeCustomer(['name' => 'Оптовый Клиент', 'customer_group_id' => wholesaleGroup()->id]);
    Order::factory()->create(['customer_id' => $ivan->id, 'status' => OrderStatus::Completed, 'total' => '1500.00']);

    $this->get(CustomerResource::getUrl('index'))->assertOk()->assertSee('Покупатели');

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$ivan, $petr, $opt])
        ->searchTable('Уникальный')->assertCanSeeTableRecords([$ivan])->assertCanNotSeeTableRecords([$petr, $opt])
        ->searchTable('petr.d@')->assertCanSeeTableRecords([$petr])->assertCanNotSeeTableRecords([$ivan])
        ->searchTable('8 (912) 123-45-67')->assertCanSeeTableRecords([$ivan])->assertCanNotSeeTableRecords([$petr])
        ->searchTable('')
        ->filterTable('verified', false)->assertCanSeeTableRecords([$petr])->assertCanNotSeeTableRecords([$ivan])
        ->removeTableFilters()
        ->filterTable('is_blocked', true)->assertCanSeeTableRecords([$petr])->assertCanNotSeeTableRecords([$ivan, $opt])
        ->removeTableFilters()
        ->filterTable('customer_group_id', $opt->customer_group_id)->assertCanSeeTableRecords([$opt])->assertCanNotSeeTableRecords([$ivan])
        ->removeTableFilters()
        ->filterTable('has_orders', true)->assertCanSeeTableRecords([$ivan])->assertCanNotSeeTableRecords([$petr, $opt])
        ->removeTableFilters()
        ->filterTable('registered', ['registered_from' => now()->addDay()->toDateString()])->assertCanNotSeeTableRecords([$ivan]);
});

it('shows the number of requests and the sum of completed ones', function (): void {
    actingAs($this->admin);
    $customer = makeCustomer();
    Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Completed, 'total' => '1500.50']);
    Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::New, 'total' => '999.00']);

    Livewire::test(ListCustomers::class)
        ->assertTableColumnStateSet('orders_count', 2, $customer)
        ->assertTableColumnFormattedStateSet('purchased_total', Money_format(150050), $customer);
});

function Money_format(int $kopecks): string
{
    return Money::format($kopecks);
}

it('opens the card with the requisites and the tabs of orders and addresses (CA-20)', function (): void {
    actingAs($this->admin);
    $customer = makeCustomer(['name' => 'Карточный Клиент']);
    CustomerCompany::factory()->create(['customer_id' => $customer->id, 'name' => 'ООО Карточка', 'inn' => '7707083893']);
    CustomerAddress::factory()->create(['customer_id' => $customer->id, 'label' => 'Склад', 'address' => 'г. Склад, ул. 1']);
    $order = Order::factory()->create(['customer_id' => $customer->id]);

    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()->assertSee('Карточный Клиент')->assertSee('ООО Карточка')->assertSee('7707083893');

    expect(CustomerResource::getRelations())->toContain(OrdersRelationManager::class)->toContain(AddressesRelationManager::class);

    Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$order]);
    Livewire::test(AddressesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertSee('Склад');
});

it('has no create, delete or sign-in-as-customer action (CA-21)', function (): void {
    actingAs($this->admin);
    $customer = makeCustomer();

    expect(CustomerResource::canCreate())->toBeFalse()
        ->and(array_keys(CustomerResource::getPages()))->toBe(['index', 'view', 'edit'])
        ->and(Gate::allows('delete', $customer))->toBeFalse();

    $test = Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()]);
    foreach (['impersonate', 'login_as', 'delete'] as $name) {
        $test->assertActionDoesNotExist($name);
    }
});

it('changes the group of a customer and writes the journal (CA-21)', function (): void {
    actingAs($this->admin);
    $customer = makeCustomer();
    $group = wholesaleGroup();

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->callAction('change_group', ['group_id' => $group->id])
        ->assertNotified();

    expect($customer->fresh()->customer_group_id)->toBe($group->id);

    $entry = AdminAuditEntry::query()->where('action', AuditAction::CustomerGroupChanged->value)->firstOrFail();
    expect($entry->user_id)->toBe($this->admin->id)->and($entry->subject_id)->toBe($customer->id)->and($entry->data)->toBe(['from' => CustomerGroup::retail()->id, 'to' => $group->id]);
});

it('refuses the guest group', function (): void {
    $guest = CustomerGroup::guest();

    expect(fn () => app(CustomerAdminService::class)->changeGroup(makeCustomer(), $guest, $this->admin))->toThrow(CustomerAccountException::class);
});

it('changes the group of many customers at once (CA-21)', function (): void {
    actingAs($this->admin);
    $group = wholesaleGroup();
    $a = makeCustomer();
    $b = makeCustomer();
    $gone = makeCustomer(['anonymized_at' => now()]);

    Livewire::test(ListCustomers::class)
        ->callTableBulkAction('change_group', [$a, $b, $gone], ['group_id' => $group->id])
        ->assertNotified();

    expect($a->fresh()->customer_group_id)->toBe($group->id)
        ->and($b->fresh()->customer_group_id)->toBe($group->id)
        ->and($gone->fresh()->customer_group_id)->not->toBe($group->id)
        ->and(AdminAuditEntry::query()->where('action', AuditAction::CustomerGroupChanged->value)->count())->toBe(2);
});

it('blocks with a reason, ends the sessions, and unblocks (CA-21, CA-31)', function (): void {
    actingAs($this->admin);
    $customer = makeCustomer();

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->callAction('block', ['reason' => 'Мошенничество'])
        ->assertNotified();

    $fresh = $customer->fresh();
    expect($fresh->is_blocked)->toBeTrue()
        ->and($fresh->blocked_reason)->toBe('Мошенничество')
        ->and($fresh->auth_version)->toBe(2);

    // The blocked customer cannot sign in; the reason is not shown to the customer.
    $this->post(route('customer.login.store'), ['email' => $customer->email, 'password' => 'password-123'])
        ->assertSessionHasErrors('email');

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertSee('Мошенничество')
        ->callAction('unblock');

    expect($customer->fresh()->is_blocked)->toBeFalse()->and($customer->fresh()->blocked_reason)->toBeNull();
    expect(AdminAuditEntry::query()->whereIn('action', [AuditAction::CustomerBlocked->value, AuditAction::CustomerUnblocked->value])->count())->toBe(2);
});

it('anonymizes at once from the card (CA-16, CA-21)', function (): void {
    actingAs($this->admin);
    $customer = makeCustomer(['name' => 'Будет Обезличен']);
    $order = Order::factory()->create(['customer_id' => $customer->id, 'customer_name' => 'Будет Обезличен']);

    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->callAction('anonymize')
        ->assertNotified()
        ->assertActionHidden('anonymize');

    expect($customer->fresh()->anonymized_at)->not->toBeNull()
        ->and($order->fresh()->customer_name)->toBe('Удалённый покупатель');
    expect(AdminAuditEntry::query()->where('action', AuditAction::CustomerAnonymized->value)->where('user_id', $this->admin->id)->count())->toBe(1);
});

it('resends the confirmation code to an unconfirmed customer only (CA-21)', function (): void {
    actingAs($this->admin);
    $unverified = makeCustomer(['email_verified_at' => null]);
    $verified = makeCustomer();

    Livewire::test(ViewCustomer::class, ['record' => $verified->getRouteKey()])->assertActionHidden('resend_code');

    Livewire::test(ViewCustomer::class, ['record' => $unverified->getRouteKey()])->callAction('resend_code')->assertNotified();

    expect(lastSentCode(VerificationPurpose::Register))->toMatch('/^\d{6}$/');
    expect(AdminAuditEntry::query()->where('action', AuditAction::CustomerCodeResent->value)->count())->toBe(1);
});

it('edits name and phone through the service and journals the change', function (): void {
    actingAs($this->admin);
    $customer = makeCustomer(['name' => 'Старое', 'phone' => '+79001112233']);

    Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
        ->fillForm(['name' => 'Новое', 'phone' => '8 (912) 345-67-89'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($customer->fresh()->name)->toBe('Новое')->and($customer->fresh()->phone)->toBe('+79123456789');
    expect(AdminAuditEntry::query()->where('action', AuditAction::CustomerUpdated->value)->firstOrFail()->data)->toBe(['fields' => ['name', 'phone']]);
});

it('gates every action by its permission (RB-05, CA-21)', function (): void {
    $customer = makeCustomer();
    $group = wholesaleGroup();
    $viewer = staffWith(['customers.view']);
    $manager = User::factory()->manager()->create();
    $service = app(CustomerAdminService::class);

    expect(fn () => $service->block($customer, 'x', $viewer))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->changeGroup($customer, $group, $viewer))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->anonymize($customer, $manager))->toThrow(AuthorizationException::class)
        ->and(fn () => $service->update($customer, ['name' => 'X'], $viewer))->toThrow(AuthorizationException::class);

    // The manager role may change the group and block, but not anonymize (25.4).
    $service->changeGroup($customer, $group, $manager);
    $service->block($customer, null, $manager);
    expect($customer->fresh()->is_blocked)->toBeTrue();
});

it('hides actions without the permission and refuses the resource without customers.view', function (): void {
    $customer = makeCustomer();

    actingAs(staffWith(['customers.view']));
    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertActionHidden('block')->assertActionHidden('change_group')->assertActionHidden('anonymize');

    actingAs(User::factory()->manager()->create());
    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->assertActionVisible('block')->assertActionVisible('change_group')->assertActionHidden('anonymize');

    actingAs(staffWith(['orders.view']));
    $this->get(CustomerResource::getUrl('index'))->assertForbidden();
});

it('does not let a customer into the admin panel (D-071)', function (): void {
    signInCustomer($this, makeCustomer());

    $this->get(CustomerResource::getUrl('index'))->assertRedirect();
});

it('shows new registrations on the dashboard widget (CA-23)', function (): void {
    actingAs($this->admin);
    makeCustomer(['created_at' => now()->subDays(2)]);
    makeCustomer(['created_at' => now()->subDays(3), 'email_verified_at' => null]);
    makeCustomer(['created_at' => now()->subDays(30)]);

    Livewire::test(NewCustomersWidget::class)->assertSee('Новые регистрации за 7 дней');

    $stats = (new ReflectionMethod(NewCustomersWidget::class, 'getStats'))->invoke(new NewCustomersWidget);
    expect($stats[0]->getValue())->toBe(2)->and($stats[1]->getValue())->toBe(1);

    actingAs(staffWith(['orders.view']));
    expect(NewCustomersWidget::canView())->toBeFalse();
    actingAs($this->admin);
    expect(NewCustomersWidget::canView())->toBeTrue();
});

it('lists the customer menu item under Продажи', function (): void {
    actingAs($this->admin);

    expect(CustomerResource::getNavigationGroup())->toBe('Продажи')->and(CustomerResource::getNavigationLabel())->toBe('Покупатели');
});

it('resolves the card by the UUID', function (): void {
    $customer = Customer::factory()->create();

    expect($customer->getRouteKey())->toBe($customer->uuid);
});
