<?php

declare(strict_types=1);

namespace App\Enums;

enum ExportType: string
{
    case Catalog = 'catalog';
    case Orders = 'orders';
    case PromoBatch = 'promo_batch';
    case CustomerOrdersXlsx = 'customer_orders_xlsx';
    case CustomerOrdersPdf = 'customer_orders_pdf';
    case PriceList = 'price_list';

    /**
     * Exports a customer makes from the cabinet (CX-01).
     */
    public function isCustomerOrders(): bool
    {
        return $this === self::CustomerOrdersXlsx || $this === self::CustomerOrdersPdf;
    }

    public function extension(): string
    {
        return $this === self::CustomerOrdersPdf ? 'pdf' : 'xlsx';
    }

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
