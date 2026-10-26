<?php

declare(strict_types=1);

namespace App\Enums;

enum EmptyCellPolicy: string
{
    case Keep = 'keep';
    case Clear = 'clear';

    public function label(): string
    {
        return __('import.empty_cell.'.$this->value);
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
