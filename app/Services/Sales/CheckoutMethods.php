<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Settings\SalesSettings;
use Illuminate\Support\Str;

/**
 * Active delivery and payment methods from the sales settings (SF-44, SF-45).
 *
 * Settings rows have no id, so a method is identified by a stable string key: the slug of its name
 * (Cyrillic is transliterated), made unique with a numeric suffix when names collide (`kurerskaya`, `kurerskaya-2`).
 * Keys are computed over ALL rows, so deactivating another method does not change a key; renaming a method
 * does (a checkout form open at that moment is rejected as an unknown method). Orders store the method NAME
 * as a snapshot, not the key.
 */
final class CheckoutMethods
{
    public function __construct(private readonly SalesSettings $settings) {}

    /**
     * @return list<DeliveryOption>
     */
    public function deliveries(): array
    {
        $keys = $this->keys($this->settings->delivery_methods);
        $options = [];

        foreach (array_values($this->settings->delivery_methods) as $index => $row) {
            if (! ($row['is_active'] ?? false)) {
                continue;
            }

            $options[] = new DeliveryOption(
                $keys[$index],
                (string) ($row['name'] ?? ''),
                isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
                (int) ($row['price'] ?? 0),
                isset($row['free_from']) ? (int) $row['free_from'] : null,
                (bool) ($row['requires_address'] ?? false),
            );
        }

        return $options;
    }

    public function delivery(?string $key): ?DeliveryOption
    {
        foreach ($this->deliveries() as $option) {
            if ($option->key === $key) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return list<PaymentOption>
     */
    public function payments(): array
    {
        $keys = $this->keys($this->settings->payment_methods);
        $options = [];

        foreach (array_values($this->settings->payment_methods) as $index => $row) {
            if (! ($row['is_active'] ?? false)) {
                continue;
            }

            $options[] = new PaymentOption(
                $keys[$index],
                (string) ($row['name'] ?? ''),
                isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            );
        }

        return $options;
    }

    public function payment(?string $key): ?PaymentOption
    {
        foreach ($this->payments() as $option) {
            if ($option->key === $key) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function keys(array $rows): array
    {
        $keys = [];
        $used = [];

        foreach (array_values($rows) as $index => $row) {
            $base = Str::slug((string) ($row['name'] ?? '')) ?: 'method';
            $key = $base;
            $suffix = 2;

            while (isset($used[$key])) {
                $key = $base.'-'.$suffix++;
            }

            $used[$key] = true;
            $keys[$index] = $key;
        }

        return $keys;
    }
}
