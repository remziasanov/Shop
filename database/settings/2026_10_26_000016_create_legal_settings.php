<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('legal.seller_details', null);
        // Template text: have it checked by a lawyer.
        $this->migrator->add('legal.consent_text', 'Я согласен(на) на обработку персональных данных');
        $this->migrator->add('legal.cookie_banner_enabled', true);
        $this->migrator->add('legal.cookie_banner_text', 'Мы используем cookie, чтобы сайт работал лучше.');
        $this->migrator->add('legal.privacy_page_id', null);
        $this->migrator->add('legal.consent_page_id', null);
    }
};
