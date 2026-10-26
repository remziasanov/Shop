<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Single source of the request list conditions (OR-02): the admin table, its tabs and the Excel export (EX-02)
 * all build their queries from the same serializable parameter array.
 *
 * Parameters (all optional): `tab` and `type` (order|reservation|inquiry, both apply), `status`,
 * `created_from` / `created_until` (Y-m-d, inclusive), `delivery_method` (snapshot name), `search`.
 *
 * @phpstan-type Params array{tab?: string|null, type?: string|null, status?: string|null, created_from?: string|null, created_until?: string|null, delivery_method?: string|null, search?: string|null}
 */
final class OrderQuery
{
    /**
     * @param  Builder<Order>  $query
     * @param  Params  $params
     * @return Builder<Order>
     */
    public static function apply(Builder $query, array $params): Builder
    {
        foreach (['tab', 'type'] as $key) {
            $type = filled($params[$key] ?? null) ? OrderType::tryFrom((string) $params[$key]) : null;

            if ($type !== null) {
                $query->where('orders.type', $type->value);
            }
        }

        $status = filled($params['status'] ?? null) ? OrderStatus::tryFrom((string) $params['status']) : null;

        if ($status !== null) {
            $query->where('orders.status', $status->value);
        }

        if (($from = self::date($params['created_from'] ?? null)) !== null) {
            $query->where('orders.created_at', '>=', $from->startOfDay());
        }

        if (($until = self::date($params['created_until'] ?? null)) !== null) {
            $query->where('orders.created_at', '<=', $until->endOfDay());
        }

        if (filled($params['delivery_method'] ?? null)) {
            $query->where('orders.delivery_method', (string) $params['delivery_method']);
        }

        if (filled($params['search'] ?? null)) {
            self::search($query, (string) $params['search']);
        }

        return $query;
    }

    /**
     * Search by number, customer name, email and phone. A phone is matched by digits, so «8 (900) 123-45-67»,
     * «+7 900 1234567» and «9001234567» find the same stored «+79001234567».
     *
     * @param  Builder<Order>  $query
     * @param  bool  $numberAndPhoneOnly  global search (AD-04) looks only at the number and phone
     */
    public static function search(Builder $query, string $term, bool $numberAndPhoneOnly = false): void
    {
        $term = trim(str_replace(['\\', '%', '_'], '', $term));

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $phone = self::phoneDigits($term);

        $query->where(function (Builder $query) use ($like, $phone, $numberAndPhoneOnly): void {
            $query->where('orders.number', 'like', $like);

            if (! $numberAndPhoneOnly) {
                $query->orWhere('orders.customer_name', 'like', $like)
                    ->orWhere('orders.customer_email', 'like', $like);
            }

            if ($phone !== null) {
                $query->orWhere('orders.customer_phone', 'like', '%'.$phone.'%');
            }
        });
    }

    /**
     * Digits to look for inside a stored «+7XXXXXXXXXX»: the country prefix typed as 7 or 8 is dropped.
     */
    public static function phoneDigits(string $term): ?string
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
            $digits = substr($digits, 1);
        } elseif (preg_match('/^\s*\+?[78][\s(\-]/', $term) === 1 && strlen($digits) > 1) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) >= 3 ? $digits : null;
    }

    private static function date(?string $value): ?CarbonImmutable
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
