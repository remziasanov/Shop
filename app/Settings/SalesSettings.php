<?php

declare(strict_types=1);

namespace App\Settings;

use App\Enums\SalesMode;
use App\Enums\StockDisplay;
use Spatie\LaravelSettings\Settings;

class SalesSettings extends Settings
{
    public SalesMode $default_mode;

    public bool $stock_tracking;

    public bool $allow_backorder;

    public StockDisplay $stock_display;

    /** Quantity at or below which "low stock" is shown. */
    public int $low_stock_threshold;

    public int $reservation_hours;

    /** MR-03: products in mode «Резерв» / «Корзина и резерв» can be reserved through the cart. */
    public bool $reservation_via_cart;

    /** MR-13: most rows of one reservation. */
    public int $reservation_max_items;

    /** Minimum order amount in kopecks (D-009). 0 = no minimum. */
    public int $min_order_amount;

    /**
     * Money values (price, free_from) are kopecks (D-009).
     *
     * Items: name, description, price, free_from, requires_address, is_active.
     */
    public array $delivery_methods;

    /**
     * Items: name, description, is_active.
     */
    public array $payment_methods;

    public string $order_prefix;

    public string $reservation_prefix;

    public string $inquiry_prefix;

    public ?string $thanks_order_text;

    public ?string $thanks_reservation_text;

    public ?string $thanks_inquiry_text;

    public static function group(): string
    {
        return 'sales';
    }
}
