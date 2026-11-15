<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Models\AdminAuditEntry;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Access\CustomerGroupService;

/**
 * RB-12: creating, changing and deleting customer groups, and choosing the group of new customers, reach the journal.
 */
beforeEach(function (): void {
    $this->actor = User::factory()->admin()->create();
    $this->actingAs($this->actor);
    $this->service = app(CustomerGroupService::class);
});

it('writes a journal entry when a group is created', function (): void {
    $group = $this->service->save(new CustomerGroup, [...CustomerGroup::factory()->make(['name' => 'Дилеры', 'code' => 'dealers'])->getAttributes()]);

    $entry = AdminAuditEntry::query()->where('action', AuditAction::CustomerGroupCreated->value)->sole();

    expect($entry->subject_id)->toBe($group->id)->and($entry->user_id)->toBe($this->actor->id);
});

it('writes the changed field names, and nothing when nothing changed', function (): void {
    $group = CustomerGroup::factory()->create(['name' => 'Дилеры', 'code' => 'dealers']);

    $this->service->save($group, ['name' => 'Дилеры']);
    expect(AdminAuditEntry::query()->where('action', AuditAction::CustomerGroupUpdated->value)->count())->toBe(0);

    $this->service->save($group, ['name' => 'Новые дилеры']);
    $entry = AdminAuditEntry::query()->where('action', AuditAction::CustomerGroupUpdated->value)->sole();

    expect($entry->data['fields'])->toBe(['name'])->and($entry->data['categories_changed'])->toBeFalse();
});

it('records a change of the category selection', function (): void {
    $group = CustomerGroup::factory()->create(['code' => 'dealers']);
    $category = Category::factory()->create();

    $this->service->save($group, ['category_access_mode' => 'only'], [$category->id]);

    expect(AdminAuditEntry::query()->where('action', AuditAction::CustomerGroupUpdated->value)->sole()->data['categories_changed'])->toBeTrue();
});

it('writes an entry for the group of new customers and for a deletion with the moved customers', function (): void {
    $group = CustomerGroup::factory()->create(['code' => 'dealers']);
    Customer::factory()->create(['customer_group_id' => $group->id]);

    $this->service->setDefaultForNew($group);
    expect(AdminAuditEntry::query()->where('action', AuditAction::CustomerGroupDefaultChanged->value)->count())->toBe(1);

    $other = CustomerGroup::factory()->create(['code' => 'vip']);
    $this->service->setDefaultForNew($other);
    $this->service->delete($group->refresh());

    $entry = AdminAuditEntry::query()->where('action', AuditAction::CustomerGroupDeleted->value)->sole();

    expect($entry->data['customers_moved'])->toBe(1)->and($entry->data['code'])->toBe('dealers');
});
