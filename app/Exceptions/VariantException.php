<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A refused operation on variants (VR-02, VR-05, VR-25…); the message is Russian and fit to show in the admin.
 */
final class VariantException extends RuntimeException
{
    /**
     * @param  list<string>  $orderNumbers  numbers of the unfinished requests that block the operation (VR-25)
     */
    private function __construct(string $message, public readonly array $orderNumbers = [])
    {
        parent::__construct($message);
    }

    public static function axesCount(): self
    {
        return new self(__('variants.errors.axes_count'));
    }

    public static function axesUnknown(): self
    {
        return new self(__('variants.errors.axes_unknown'));
    }

    public static function axesLocked(): self
    {
        return new self(__('variants.errors.axes_locked'));
    }

    public static function notVariantCard(): self
    {
        return new self(__('variants.errors.not_variant_card'));
    }

    public static function valuesInvalid(): self
    {
        return new self(__('variants.errors.values_invalid'));
    }

    public static function comboExists(): self
    {
        return new self(__('variants.errors.combo_exists'));
    }

    public static function limitExceeded(int $max, int $total): self
    {
        return new self(__('variants.errors.limit', ['max' => $max, 'total' => $total]));
    }

    public static function priceInvalid(string $field): self
    {
        return new self(__('variants.errors.price_invalid', ['field' => __('variants.fields.'.$field)]));
    }

    public static function stockInvalid(): self
    {
        return new self(__('variants.errors.stock_invalid'));
    }

    /**
     * @param  list<string>  $orderNumbers
     */
    public static function unfinishedRequests(array $orderNumbers): self
    {
        return new self(__('variants.errors.unfinished_requests', ['numbers' => implode(', ', $orderNumbers)]), $orderNumbers);
    }
}
