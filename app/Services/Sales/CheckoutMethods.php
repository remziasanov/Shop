<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Services\Access\ViewerContext;
use App\Settings\SalesSettings;

/**
 * Active delivery and payment methods from the sales settings (SF-44, SF-45).
 *
 * A method is identified by its immutable `code` (CG-09): generated from the name when the method is created (a settings
 * migration filled it for existing rows), never changed by a rename. Rows without a code (old data, tests) get the same
 * generated value on the fly ({@see MethodCodes}). Orders store the method NAME as a snapshot, not the code.
 *
 * Only the methods the viewer's customer group allows are offered and accepted (CG-09, CG-14).
 */
final class CheckoutMethods
{
    public function __construct(
        private readonly SalesSettings $settings,
        private readonly ViewerContext $viewer,
    ) {}

    /**
     * @return list<DeliveryOption>
     */
    public function deliveries(): array
    {
        $keys = MethodCodes::codesOf($this->settings->delivery_methods);
        $access = $this->viewer->access();
        $options = [];

        foreach (array_values($this->settings->delivery_methods) as $index => $row) {
            if (! ($row['is_active'] ?? false) || ! $access->allowsDelivery($keys[$index])) {
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
        $keys = MethodCodes::codesOf($this->settings->payment_methods);
        $access = $this->viewer->access();
        $options = [];

        foreach (array_values($this->settings->payment_methods) as $index => $row) {
            if (! ($row['is_active'] ?? false) || ! $access->allowsPayment($keys[$index])) {
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
}
