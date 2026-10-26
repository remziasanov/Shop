<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Product marks usable as price rule targets (scope "flags").
 */
enum ProductFlag: string
{
    case Featured = 'featured';
    case New = 'new';

    public function label(): string
    {
        return __('pricing.flags.'.$this->value);
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
