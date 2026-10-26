<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderType;
use App\Models\OrderSequence;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;

/**
 * Request numbers: `{prefix}-{number padded to 5}`, e.g. Z-00042 (D-045). The counter lives in
 * `order_sequences` per type and is incremented under `lockForUpdate()`. Call it inside the transaction that
 * creates the request, so a rolled back request does not burn a number.
 */
final class OrderNumberGenerator
{
    public function __construct(private readonly SalesSettings $settings) {}

    public function next(OrderType $type): string
    {
        $number = DB::transaction(function () use ($type): int {
            // Make sure the row exists (a concurrent insert is ignored), then lock it.
            DB::table('order_sequences')->insertOrIgnore(['type' => $type->value, 'last_number' => 0]);

            /** @var OrderSequence $sequence */
            $sequence = OrderSequence::query()->where('type', $type->value)->lockForUpdate()->firstOrFail();
            $sequence->last_number++;
            $sequence->save();

            return $sequence->last_number;
        });

        return $type->prefix($this->settings).'-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT);
    }
}
