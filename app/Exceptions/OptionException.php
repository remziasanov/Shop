<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A refused operation on the option dictionary (VR-01, VR-26); the message is Russian and fit to show in the admin.
 * `$count` carries the number of cards where the rule is about usage.
 */
final class OptionException extends RuntimeException
{
    public function __construct(string $message, public readonly int $count = 0)
    {
        parent::__construct($message);
    }

    public static function valueRequired(string $option): self
    {
        return new self(__('admin_variants.errors.value_required', ['option' => $option]));
    }

    public static function valueExists(string $value): self
    {
        return new self(__('admin_variants.errors.value_exists', ['value' => $value]));
    }

    public static function valueInUse(string $value, int $count): self
    {
        return new self(__('admin_variants.errors.value_in_use', ['value' => $value, 'count' => $count]), $count);
    }

    public static function typeInUse(string $name, int $count): self
    {
        return new self(__('admin_variants.errors.type_in_use', ['name' => $name, 'count' => $count]), $count);
    }

    public static function mergeSame(): self
    {
        return new self(__('admin_variants.errors.merge_same'));
    }

    public static function mergeOtherOption(): self
    {
        return new self(__('admin_variants.errors.merge_other_option'));
    }

    /**
     * @param  list<string>  $skus  variants that would get a combination which already exists
     */
    public static function mergeConflict(array $skus): self
    {
        return new self(__('admin_variants.errors.merge_conflict', ['skus' => implode(', ', array_slice($skus, 0, 10))]), count($skus));
    }

    public static function notFilterable(string $name): self
    {
        return new self(__('admin_variants.errors.not_filterable', ['name' => $name]));
    }

    public static function colorInvalid(): self
    {
        return new self(__('admin_variants.errors.hex_invalid'));
    }

    public static function swatchNotFound(): self
    {
        return new self(__('admin_variants.errors.swatch_not_found'));
    }
}
