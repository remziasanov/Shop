<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain error of characteristics and filters (FL-01…FL-05, FL-20). The message is already localized; `$count`
 * carries the number of affected products where the rule is about usage.
 */
final class AttributeException extends RuntimeException
{
    public function __construct(string $message, public readonly int $count = 0)
    {
        parent::__construct($message);
    }

    public static function nameRequired(): self
    {
        return new self(__('attributes.errors.name_required'));
    }

    public static function unknownAttribute(string $name): self
    {
        return new self(__('attributes.errors.unknown_attribute', ['name' => $name]));
    }

    public static function typeLocked(int $count): self
    {
        return new self(__('attributes.errors.type_locked', ['count' => $count]), $count);
    }

    public static function inUse(int $count): self
    {
        return new self(__('attributes.errors.in_use', ['count' => $count]), $count);
    }

    public static function valueInUse(string $value, int $count): self
    {
        return new self(__('attributes.errors.value_in_use', ['value' => $value, 'count' => $count]), $count);
    }

    public static function valueExists(string $value): self
    {
        return new self(__('attributes.errors.value_exists', ['value' => $value]));
    }

    public static function valueRequired(string $attribute): self
    {
        return new self(__('attributes.errors.value_required', ['name' => $attribute]));
    }

    public static function valueTooLong(string $attribute, int $max): self
    {
        return new self(__('attributes.errors.value_too_long', ['name' => $attribute, 'max' => $max]));
    }

    public static function singleValueOnly(string $attribute): self
    {
        return new self(__('attributes.errors.single_value_only', ['name' => $attribute]));
    }

    public static function notANumber(string $attribute, string $value): self
    {
        return new self(__('attributes.errors.not_a_number', ['name' => $attribute, 'value' => $value]));
    }

    public static function numberOutOfRange(string $attribute, string $value): self
    {
        return new self(__('attributes.errors.number_out_of_range', ['name' => $attribute, 'value' => $value]));
    }

    public static function notABoolean(string $attribute, string $value): self
    {
        return new self(__('attributes.errors.not_a_boolean', ['name' => $attribute, 'value' => $value]));
    }

    public static function valueNotOfAttribute(string $attribute, int $valueId): self
    {
        return new self(__('attributes.errors.value_not_of_attribute', ['name' => $attribute, 'id' => $valueId]));
    }

    public static function notAList(): self
    {
        return new self(__('attributes.errors.not_a_list'));
    }

    public static function mergeSame(): self
    {
        return new self(__('attributes.errors.merge_same'));
    }

    public static function mergeOtherAttribute(): self
    {
        return new self(__('attributes.errors.merge_other_attribute'));
    }

    public static function unitForNumberOnly(): self
    {
        return new self(__('attributes.errors.unit_for_number_only'));
    }

    public static function filtersLimit(int $max): self
    {
        return new self(__('attributes.errors.filters_limit', ['max' => $max]));
    }

    public static function duplicateFilter(): self
    {
        return new self(__('attributes.errors.duplicate_filter'));
    }

    public static function duplicateInProduct(string $attribute): self
    {
        return new self(__('attributes.errors.duplicate_in_product', ['name' => $attribute]));
    }

    public static function migrationPending(int $count): self
    {
        return new self(__('attributes.errors.migration_pending', ['count' => $count]), $count);
    }

    public static function legacyEditorStale(): self
    {
        return new self(__('attributes.errors.legacy_editor_stale'));
    }
}
