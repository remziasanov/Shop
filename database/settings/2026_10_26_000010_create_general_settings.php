<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.shop_name', 'Магазин');
        $this->migrator->add('general.tagline', null);
        $this->migrator->add('general.logo', null);
        $this->migrator->add('general.favicon', null);
        $this->migrator->add('general.phones', []);
        $this->migrator->add('general.email', null);
        $this->migrator->add('general.address', null);
        $this->migrator->add('general.working_hours', null);
        $this->migrator->add('general.social_links', []);
        $this->migrator->add('general.footer_text', null);
        $this->migrator->add('general.shop_open', true);
        $this->migrator->add('general.closed_message', 'Магазин временно закрыт. Мы скоро вернёмся.');
    }
};
