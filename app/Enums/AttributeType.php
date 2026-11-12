<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Type of a characteristic (FL-01): decides how values are stored and filtered.
 */
enum AttributeType: string
{
    case List = 'list';
    case Number = 'number';
    case Boolean = 'boolean';

    public function label(): string
    {
        return __('attributes.types.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
