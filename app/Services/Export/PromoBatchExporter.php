<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoRedemption;

/**
 * Codes of one generated batch to .xlsx (PM-16): code, status, used, date of use, order number. Parameters:
 * `['batch_id' => int]`, so the export can run in the request or in the queue like the other exports (EX-03).
 *
 * @phpstan-type Params array{batch_id?: int}
 */
final class PromoBatchExporter
{
    public const CHUNK = 500;

    /**
     * @param  Params  $params
     */
    public function count(array $params): int
    {
        return PromoCode::query()->where('batch_id', (int) ($params['batch_id'] ?? 0))->count();
    }

    /**
     * Writes the file and returns the number of rows.
     *
     * @param  Params  $params
     */
    public function export(array $params, string $path): int
    {
        $book = new XlsxBook($path);
        $rows = 0;

        try {
            $book->sheet(__('promo.export.sheet'), [1 => 26, 2 => 18, 3 => 14, 4 => 18, 5 => 16]);
            $book->header(array_map(
                fn (string $key): string => __('promo.export.columns.'.$key),
                ['code', 'status', 'used', 'used_at', 'order'],
            ));

            PromoCode::query()
                ->where('batch_id', (int) ($params['batch_id'] ?? 0))
                ->chunkById(self::CHUNK, function ($codes) use ($book, &$rows): void {
                    $redemptions = PromoRedemption::query()
                        ->whereIn('promo_code_id', $codes->pluck('id')->all())
                        ->orderBy('id')
                        ->get()
                        ->groupBy('promo_code_id');
                    $numbers = Order::withTrashed()
                        ->whereIn('id', $redemptions->flatten(1)->pluck('order_id')->unique()->all())
                        ->pluck('number', 'id');

                    foreach ($codes as $code) {
                        $latest = $redemptions->get($code->id)?->last();
                        $active = $latest !== null && $latest->released_at === null;

                        $book->row([
                            $code->code,
                            match (true) {
                                $active => __('promo.export.status_used'),
                                $latest !== null => __('promo.export.status_released'),
                                ! $code->is_active => __('promo.export.status_inactive'),
                                default => __('promo.export.status_unused'),
                            },
                            __($active ? 'promo.export.yes' : 'promo.export.no'),
                            $active ? $latest->created_at?->format('d.m.Y H:i') : null,
                            $active ? $numbers->get($latest->order_id) : null,
                        ]);
                        $rows++;
                    }
                });
        } finally {
            $book->close();
        }

        return $rows;
    }
}
