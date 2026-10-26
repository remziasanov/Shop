<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderType;
use App\Models\OrderSequence;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Request numbers: `{prefix}-{number padded to 5}`, e.g. Z-00042 (D-045). The counter lives in
 * `order_sequences` per type and is incremented under `lockForUpdate()`; the rows are seeded by a migration. Call it inside the transaction that
 * creates the request, so a rolled back request does not burn a number.
 */
final class OrderNumberGenerator
{
    public function __construct(private readonly SalesSettings $settings) {}

    public function next(OrderType $type): string
    {
        $number = DB::transaction(function () use ($type): int {
            // The rows are seeded by a migration (D-053), so this is a plain locking read + update: no
            // INSERT IGNORE (its shared lock on an existing row deadlocks with a concurrent request on MySQL).
            $sequence = OrderSequence::query()->where('type', $type->value)->lockForUpdate()->first();

            if ($sequence === null) {
                // Defensive: the row can only be missing when the seeding migration did not run. Creating it
                // inside someone else's transaction would bring the deadlock back, so it is allowed only when
                // this is the outermost transaction.
                if (DB::transactionLevel() > 1) {
                    throw new RuntimeException("Request number sequence '{$type->value}' is missing; run the migrations.");
                }

                DB::table('order_sequences')->insertOrIgnore(['type' => $type->value, 'last_number' => 0]);
                $sequence = OrderSequence::query()->where('type', $type->value)->lockForUpdate()->firstOrFail();
            }

            $sequence->last_number++;
            $sequence->save();

            return $sequence->last_number;
        });

        return $type->prefix($this->settings).'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
