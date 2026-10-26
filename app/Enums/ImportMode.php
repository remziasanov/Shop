<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportMode: string
{
    case CreateUpdate = 'create_update';
    case PricesStock = 'prices_stock';
    case FullSync = 'full_sync';

    public function label(): string
    {
        return __('import.modes.'.$this->value);
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
