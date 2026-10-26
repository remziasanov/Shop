<?php

declare(strict_types=1);

namespace App\Enums;

enum ExportType: string
{
    case Catalog = 'catalog';
    case Orders = 'orders';

    public function label(): string
    {
        return __('import.export_types.'.$this->value);
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
