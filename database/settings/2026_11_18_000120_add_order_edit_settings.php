<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * OE-09, OE-10: «Письмо покупателю при изменении состава» (the default of the editor checkbox) and the share of the
 * manager's discount above which the editor warns (percent of the items total).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('notifications.notify_customer_order_changed', true);
        $this->migrator->add('notifications.order_discount_warning_percent', 30);
    }

    public function down(): void
    {
        $this->migrator->delete('notifications.notify_customer_order_changed');
        $this->migrator->delete('notifications.order_discount_warning_percent');
    }
};
