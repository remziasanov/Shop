<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('seo.product_title_template', '{name} — купить в {shop}');
        $this->migrator->add('seo.product_description_template', '{name}. {short_description}');
        $this->migrator->add('seo.category_title_template', '{name} — каталог {shop}');
        $this->migrator->add('seo.category_description_template', '{name}: товары в наличии и под заказ. {shop}');
        $this->migrator->add('seo.home_title', null);
        $this->migrator->add('seo.home_description', null);
        $this->migrator->add('seo.robots_txt', "User-agent: *\nDisallow: /cart\nDisallow: /checkout\nDisallow: /search");
        $this->migrator->add('seo.head_code', null);
        $this->migrator->add('seo.body_code', null);
        $this->migrator->add('seo.verification_meta', null);
        $this->migrator->add('seo.og_image', null);
    }
};
