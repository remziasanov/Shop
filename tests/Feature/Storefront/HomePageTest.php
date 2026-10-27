<?php

declare(strict_types=1);

use App\Settings\AppearanceSettings;
use App\Settings\GeneralSettings;

beforeEach(function () {
    $this->withoutVite();
});

it('renders the home page with the shop name', function () {
    $name = app(GeneralSettings::class)->shop_name;

    $this->get('/')
        ->assertOk()
        ->assertSee($name)
        ->assertSee('lang="ru"', false);
});

it('shows the new shop name after settings change', function () {
    $settings = app(GeneralSettings::class);
    $settings->shop_name = 'Лавка Тест';
    $settings->save();

    $this->get(route('home'))->assertOk()->assertSee('Лавка Тест');
});

it('exposes configured colors as CSS variables', function () {
    $appearance = app(AppearanceSettings::class);
    $appearance->primary_color = '#112233';
    $appearance->accent_color = '#abcdef';
    $appearance->save();

    $this->get('/')
        ->assertOk()
        ->assertSee('--shop-primary: #112233;', false)
        ->assertSee('--shop-accent: #abcdef;', false);
});

it('rejects non-hex colors', function () {
    $appearance = app(AppearanceSettings::class);
    $appearance->primary_color = 'red;} body{display:none';
    $appearance->save();

    $this->get('/')
        ->assertOk()
        ->assertDontSee('display:none', false);
});
