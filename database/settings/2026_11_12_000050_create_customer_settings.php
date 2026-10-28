<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * CA-23, CA-01: defaults keep the v1.0 behaviour (guest orders allowed); registration is on, since the account only
 * adds the «Войти» link to the storefront (NF-56).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('customers.registration_enabled', true);
        $this->migrator->add('customers.allow_guest_checkout', true);
        $this->migrator->add('customers.code_ttl_minutes', 15);
        $this->migrator->add('customers.code_max_attempts', 5);
        $this->migrator->add('customers.code_resend_seconds', 60);
        $this->migrator->add('customers.unverified_retention_days', 7);
        $this->migrator->add('customers.deletion_grace_days', 3);
        $this->migrator->add('customers.password_min_length', 8);
        $this->migrator->add('customers.code_mail_warning', 'Никому не сообщайте этот код, даже сотрудникам магазина. Если вы не запрашивали код, просто проигнорируйте письмо.');
        $this->migrator->add('customers.code_mail_footer', '');
    }
};
