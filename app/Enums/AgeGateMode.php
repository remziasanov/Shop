<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the age overlay behaves (AG-02, D-070).
 */
enum AgeGateMode: string
{
    /** On the first page of a visit, for the whole site. */
    case Site = 'site';

    /** Only on category and product pages with an effective restriction. */
    case Restricted = 'restricted';

    public function label(): string
    {
        return __('age_gate.modes.'.$this->value);
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
