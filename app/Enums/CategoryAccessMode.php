<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How `customer_group_categories` is read (CG-05): all categories, all except the selected ones, only the selected ones.
 */
enum CategoryAccessMode: string
{
    case All = 'all';
    case AllExcept = 'all_except';
    case Only = 'only';

    public function label(): string
    {
        return __('customer_groups.enums.access_mode.'.$this->value);
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
