<?php

declare(strict_types=1);

namespace App\Enums;

enum RoundingScope: string
{
    case Changed = 'changed';
    case All = 'all';

    public function label(): string
    {
        return __('shop.enums.rounding_scope.'.$this->value);
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
