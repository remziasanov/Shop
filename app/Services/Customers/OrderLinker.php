<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Str;

/**
 * Links guest requests to a customer who proved ownership of the address (CA-14): every request with the same
 * e-mail (any letter case) and no customer yet gets the customer id. Works in batches of 200. Without a confirmed
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

        $email = Str::lower(trim($customer->email));
        $linked = 0;

        do {
            $ids = Order::query()
                ->whereNull('customer_id')
                ->whereRaw('lower(customer_email) = ?', [$email])
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            // The condition is repeated: a parallel run may have linked some of them to the same customer already.
            $linked += Order::query()->whereKey($ids->all())->whereNull('customer_id')->update(['customer_id' => $customer->id]);
        } while ($ids->count() === self::BATCH);

        return $linked;
    }
}
