<?php

declare(strict_types=1);

namespace App\Enums;

enum RoundingStep: int
{
    case None = 0;
    case One = 1;
    case Ten = 10;
    case Fifty = 50;
    case Hundred = 100;

    public function label(): string
    {
        return __('shop.enums.rounding_step.'.$this->value);
    }

    /**
     * @return array<int, string>
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
