<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Settings\CustomerSettings;
use Throwable;

/**
 * Scheduled upkeep of the customer tables: anonymization after the cancel period (CA-16), removal of accounts that
 * never confirmed their address (CA-24). Both work in chunks and stop at the given deadline (`microtime(true)`), so a
 * run fits the limits of shared hosting; the next run continues.
 */
final class CustomerRetentionService
{
    private const CHUNK = 50;

    public function __construct(
        private readonly CustomerSettings $settings,
        private readonly CustomerAnonymizer $anonymizer,
    ) {}

    /**
     * Anonymizes customers whose deletion request is older than the cancel period (`deletion_grace_days`).
     */
    public function anonymizeDue(?float $deadline = null): int
    {
        $threshold = now()->subDays(max(0, $this->settings->deletion_grace_days));
        $done = 0;
        $lastId = 0;

        do {
            $customers = Customer::query()
                ->whereNotNull('deletion_requested_at')
                ->whereNull('anonymized_at')
                ->where('deletion_requested_at', '<=', $threshold)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get();

            foreach ($customers as $customer) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return $done;
                }

                $lastId = $customer->id;

                try {
                    if ($this->anonymizer->anonymize($customer, null, 'system')) {
                        $done++;
                    }
                } catch (Throwable $e) {
                    // One broken account must not stop the others; it is retried on the next run.
                    report($e);
                }
            }
        } while ($customers->count() === self::CHUNK);

        return $done;
    }

    /**
     * Deletes accounts with an unconfirmed address older than `unverified_retention_days` (CA-24). Such an account
     * cannot own requests; one that somehow does is left alone.
     */
    public function purgeUnverified(?float $deadline = null): int
    {
        $threshold = now()->subDays(max(1, $this->settings->unverified_retention_days));
        $deleted = 0;

        do {
            $ids = Customer::query()
                ->whereNull('email_verified_at')
                ->where('created_at', '<', $threshold)
                ->whereDoesntHave('orders')
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // Addresses, codes and the other child rows go with the account (foreign keys cascade).
            $deleted += Customer::query()->whereKey($ids->all())->delete();
        } while ($ids->count() === self::CHUNK && ($deadline === null || microtime(true) < $deadline));

        return $deleted;
    }
}
