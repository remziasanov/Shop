<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Exceptions\CustomerThrottledException;
use App\Models\Customer;

/**
 * «Удалить аккаунт» in the security section of the cabinet (CA-16). The customer asks for a code, enters it, and the
 * account is marked with `deletion_requested_at` and every session ends (CA-31); signing in with a code during the
 * grace period cancels the request (see {@see CustomerAuthService::authenticateByCode()}), and
 * {@see CustomerRetentionService::anonymizeDue()} performs the anonymization when the period has passed.
 */
final class AccountDeletionService
{
    public function __construct(private readonly VerificationCodeService $codes) {}

    /**
     * Step 1: the code goes to the customer's own address.
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     */
    public function requestCode(Customer $customer, string $ip): void
    {
        $this->codes->request(VerificationPurpose::DeleteAccount, $customer->email, $ip, $customer);
    }

    /**
     * Step 2: the right code requests the deletion. The account cannot sign in until the request is cancelled.
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     */
    public function confirm(Customer $customer, string $code, string $ip): Customer
    {
        $row = $this->codes->verify(VerificationPurpose::DeleteAccount, $customer->email, $code, $ip);

        if ($row->customer_id !== $customer->id) {
            throw new CustomerAuthException(__('account.errors.code_invalid'), 'code');
        }

        $customer->forceFill(['deletion_requested_at' => now()])->save();
        $customer->bumpAuthVersion();

        return $customer;
    }
}
