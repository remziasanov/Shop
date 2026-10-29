<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\AuditAction;
use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAccountException;
use App\Exceptions\CustomerAuthException;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Users\AuditLogger;
use App\Services\Users\PermissionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What staff do with a customer (CA-21): edit the contact fields, change the group, block / unblock with a reason,
 * anonymize at once, resend the confirmation code. Each action checks the permission of the actor again (the UI
 * only hides buttons) and writes the journal (RB-12) with ids only. There is no sign-in as a customer.
 */
final class CustomerAdminService
{
    public function __construct(
        private readonly PermissionService $permissions,
        private readonly AuditLogger $audit,
        private readonly CustomerAnonymizer $anonymizer,
        private readonly VerificationCodeService $codes,
    ) {}

    /**
     * @param  array{name?: string, phone?: string|null}  $data
     *
     * @throws AuthorizationException
     */
    public function update(Customer $customer, array $data, User $actor): Customer
    {
        $this->authorize($actor, 'customers.update');
        $this->assertActive($customer);

        $customer->forceFill(array_intersect_key($data, ['name' => true, 'phone' => true]));
        $changed = array_keys($customer->getDirty());

        if ($changed !== []) {
            $customer->save();
            $this->audit->record(AuditAction::CustomerUpdated, $actor, $customer, ['fields' => $changed]);
        }

        return $customer;
    }

    /**
     * @throws AuthorizationException
     * @throws CustomerAccountException the group cannot be assigned, or the customer is anonymized
     */
    public function changeGroup(Customer $customer, CustomerGroup $group, User $actor): Customer
    {
        $this->authorize($actor, 'customers.change_group');
        $this->assertActive($customer);

        if ($group->code === CustomerGroup::GUEST || ! $group->exists) {
            throw new CustomerAccountException(__('customers.errors.group_not_assignable'));
        }

        $from = $customer->customer_group_id;

        if ($from !== $group->id) {
            $customer->forceFill(['customer_group_id' => $group->id])->save();
            $this->audit->record(AuditAction::CustomerGroupChanged, $actor, $customer, ['from' => $from, 'to' => $group->id]);
        }

        return $customer;
    }

    /**
     * Bulk change; customers that cannot be changed are skipped.
     *
     * @param  iterable<Model>  $customers  records that are not customers are skipped
     * @return int the number of customers whose group was changed
     *
     * @throws AuthorizationException
     */
    public function changeGroupMany(iterable $customers, CustomerGroup $group, User $actor): int
    {
        $this->authorize($actor, 'customers.change_group');

        $changed = 0;

        foreach ($customers as $customer) {
            if (! $customer instanceof Customer || $customer->anonymized_at !== null || $customer->customer_group_id === $group->id) {
                continue;
            }

            $this->changeGroup($customer, $group, $actor);
            $changed++;
        }

        return $changed;
    }

    /**
     * Blocks the account: no sign-in, every session ends (CA-21, CA-31). The reason is for staff only.
     *
     * @throws AuthorizationException
     * @throws CustomerAccountException
     */
    public function block(Customer $customer, ?string $reason, User $actor): Customer
    {
        $this->authorize($actor, 'customers.block');
        $this->assertActive($customer);

        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);

        DB::transaction(function () use ($customer, $reason, $actor): void {
            $customer->forceFill(['is_blocked' => true, 'blocked_reason' => $reason])->save();
            $customer->bumpAuthVersion();
            $this->audit->record(AuditAction::CustomerBlocked, $actor, $customer, ['has_reason' => $reason !== null]);
        });

        return $customer;
    }

    /**
     * @throws AuthorizationException
     * @throws CustomerAccountException
     */
    public function unblock(Customer $customer, User $actor): Customer
    {
        $this->authorize($actor, 'customers.block');
        $this->assertActive($customer);

        $customer->forceFill(['is_blocked' => false, 'blocked_reason' => null])->save();
        $this->audit->record(AuditAction::CustomerUnblocked, $actor, $customer);

        return $customer;
    }

    /**
     * «Обезличить сейчас» (CA-16, CA-17).
     *
     * @throws AuthorizationException
     */
    public function anonymize(Customer $customer, User $actor): bool
    {
        $this->authorize($actor, 'customers.anonymize');

        return $this->anonymizer->anonymize($customer, $actor, 'admin');
    }

    /**
     * Sends the registration code again to a customer whose address is not confirmed.
     *
     * @throws AuthorizationException
     * @throws CustomerAccountException
     * @throws CustomerAuthException the pause between two codes has not passed
     */
    public function resendConfirmationCode(Customer $customer, User $actor, string $ip): void
    {
        $this->authorize($actor, 'customers.update');
        $this->assertActive($customer);

        if ($customer->hasVerifiedEmail()) {
            throw new CustomerAccountException(__('customers.errors.already_verified'));
        }

        $this->codes->request(VerificationPurpose::Register, $customer->email, $ip, $customer);
        $this->audit->record(AuditAction::CustomerCodeResent, $actor, $customer);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, string $permission): void
    {
        if (! $this->permissions->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @throws CustomerAccountException
     */
    private function assertActive(Customer $customer): void
    {
        if ($customer->anonymized_at !== null) {
            throw new CustomerAccountException(__('customers.errors.anonymized'));
        }
    }
}
