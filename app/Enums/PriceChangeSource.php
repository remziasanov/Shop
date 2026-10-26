<?php

declare(strict_types=1);

namespace App\Enums;

enum PriceChangeSource: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Bulk = 'bulk';

    public function label(): string
    {
        return __('pricing.sources.'.$this->value);
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
