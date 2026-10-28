<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * AG-02. Disabled by default: after the update the shop behaves as before (TZ 16.4).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('age_gate.enabled', false);
        $this->migrator->add('age_gate.mode', 'site');
        $this->migrator->add('age_gate.site_min_age', 18);
        $this->migrator->add('age_gate.remember_days', 30);
        $this->migrator->add('age_gate.title', 'Вам уже исполнилось {age} лет?');
        $this->migrator->add('age_gate.text', 'На сайте представлены товары, которые можно приобретать только с {age} лет. Подтвердите свой возраст, чтобы продолжить.');
        $this->migrator->add('age_gate.yes_label', 'Да, мне есть {age}');
        $this->migrator->add('age_gate.no_label', 'Нет');
        $this->migrator->add('age_gate.decline_message', 'К сожалению, содержимое этого раздела доступно только совершеннолетним посетителям.');
        $this->migrator->add('age_gate.show_badge', true);
    }
};
