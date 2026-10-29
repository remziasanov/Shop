<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Links guest requests to a customer who proved ownership of the address (CA-14): every request whose e-mail has the
 * same canonical form ({@see EmailNormalizer}: any letter case, never an accent look-alike) and no customer yet gets
 * the customer id. Works in batches of 200. Without a confirmed
 * address nothing is linked. Requests placed under an account already carry their customer id.
 */
final class OrderLinker
{
    public const BATCH = 200;

    /**
     * @return int the number of requests linked
     */
    public function link(Customer $customer): int
    {
        if (! $customer->hasVerifiedEmail() || $customer->anonymized_at !== null) {
            return 0;
        }

        $email = EmailNormalizer::normalize($customer->email);

        if ($email === null) {
            return 0;
        }

        $linked = 0;
        $lastId = 0;
        // MySQL compares utf8mb4 *_ci columns without letter case (and without accents) and can use the index on
        // `customer_email`; SQLite (tests) needs the case-insensitive collation spelled out. The SQL result is only a
        // superset: every candidate is compared again in canonical form below.
        $caseInsensitive = DB::connection()->getDriverName() === 'sqlite';

        do {
            $rows = Order::query()
                ->whereNull('customer_id')
                ->where('id', '>', $lastId)
                ->when(
                    $caseInsensitive,
                    fn ($query) => $query->whereRaw('customer_email = ? COLLATE NOCASE', [$email]),
                    fn ($query) => $query->where('customer_email', $email),
                )
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('customer_email', 'id');

            if ($rows->isEmpty()) {
                break;
            }

            $lastId = (int) $rows->keys()->last();

            // A look-alike (`victim@gmáil.com` for `victim@gmail.com`) is not the customer's address (CA-14).
            $ids = $rows
                ->filter(fn ($orderEmail): bool => is_string($orderEmail) && EmailNormalizer::normalize($orderEmail) === $email)
                ->keys()
                ->all();

            if ($ids !== []) {
                // The condition is repeated: a parallel run may have linked some of them to the same customer already.
                $linked += Order::query()->whereKey($ids)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            }
        } while ($rows->count() === self::BATCH);

        return $linked;
    }
}
