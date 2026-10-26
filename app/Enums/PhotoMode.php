<?php

declare(strict_types=1);

namespace App\Enums;

enum PhotoMode: string
{
    case Replace = 'replace';
    case Append = 'append';
    case Skip = 'skip';

    public function label(): string
    {
        return __('import.photo_mode.'.$this->value);
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
