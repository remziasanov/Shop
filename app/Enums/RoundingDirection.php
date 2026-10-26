<?php

declare(strict_types=1);

namespace App\Enums;

enum RoundingDirection: string
{
    case Up = 'up';
    case Nearest = 'nearest';
    case Down = 'down';

    public function label(): string
    {
        return __('shop.enums.rounding_direction.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }
}
