<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * MR-03, MR-13: reservation of several products through the cart and the size limit of one reservation.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sales.reservation_via_cart', true);
        $this->migrator->add('sales.reservation_max_items', 20);
    }

    public function down(): void
    {
        $this->migrator->delete('sales.reservation_via_cart');
        $this->migrator->delete('sales.reservation_max_items');
    }
};
