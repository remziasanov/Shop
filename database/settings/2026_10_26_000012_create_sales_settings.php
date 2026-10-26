<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sales.default_mode', 'cart');
        $this->migrator->add('sales.stock_tracking', true);
        $this->migrator->add('sales.allow_backorder', false);
        $this->migrator->add('sales.stock_display', 'presence');
        $this->migrator->add('sales.low_stock_threshold', 3);
        $this->migrator->add('sales.reservation_hours', 48);
        // Money is stored in kopecks (D-009).
        $this->migrator->add('sales.min_order_amount', 0);
        $this->migrator->add('sales.delivery_methods', [
            [
                'name' => 'Самовывоз',
                'description' => null,
                'price' => 0,
                'free_from' => null,
                'requires_address' => false,
                'is_active' => true,
            ],
            [
                'name' => 'Курьерская доставка',
                'description' => null,
                'price' => 30000,
                'free_from' => 500000,
                'requires_address' => true,
                'is_active' => true,
            ],
        ]);
        $this->migrator->add('sales.payment_methods', [
            ['name' => 'Наличными при получении', 'description' => null, 'is_active' => true],
            ['name' => 'Картой при получении', 'description' => null, 'is_active' => true],
        ]);
        $this->migrator->add('sales.order_prefix', 'Z');
        $this->migrator->add('sales.reservation_prefix', 'R');
        $this->migrator->add('sales.inquiry_prefix', 'Q');
        $this->migrator->add('sales.thanks_order_text', 'Спасибо за заказ! Мы свяжемся с вами для подтверждения.');
        $this->migrator->add('sales.thanks_reservation_text', 'Спасибо! Товар зарезервирован. Мы свяжемся с вами для подтверждения.');
        $this->migrator->add('sales.thanks_inquiry_text', 'Спасибо за заявку! Мы свяжемся с вами в ближайшее время.');
    }
};
