<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageAppearanceSettings;
use App\Filament\Pages\Settings\ManageGeneralSettings;
use App\Filament\Pages\Settings\ManageLegalSettings;
use App\Filament\Pages\Settings\ManageNotificationSettings;
use App\Filament\Pages\Settings\ManagePricingSettings;
use App\Filament\Pages\Settings\ManageSalesSettings;
use App\Filament\Pages\Settings\ManageSeoSettings;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;

dataset('settings pages', [
    ManageGeneralSettings::class,
    ManageAppearanceSettings::class,
    ManageSalesSettings::class,
    ManagePricingSettings::class,
    ManageNotificationSettings::class,
    ManageSeoSettings::class,
    ManageLegalSettings::class,
]);

it('redirects guests to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('shows the login form', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Авторизоваться');
});

it('lets an admin open the panel and every settings page', function (string $page) {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin')->assertOk();
    $this->get($page::getUrl())->assertOk();
})->with('settings pages');

it('lets an admin open the users resource', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(UserResource::getUrl('index'))->assertOk();
    $this->get(UserResource::getUrl('create'))->assertOk();
});

it('lets a manager into the panel but not into settings (403)', function (string $page) {
    $this->actingAs(User::factory()->manager()->create());

    $this->get('/admin')->assertOk();
    $this->get($page::getUrl())->assertForbidden();
})->with('settings pages');

it('forbids a manager from the users resource', function () {
    $this->actingAs(User::factory()->manager()->create());

    $this->get(UserResource::getUrl('index'))->assertForbidden();
    $this->get(UserResource::getUrl('create'))->assertForbidden();
});

it('hides settings and users from a manager navigation', function () {
    $this->actingAs(User::factory()->manager()->create());

    $this->get('/admin')
        ->assertOk()
        ->assertDontSee('Пользователи')
        ->assertDontSee('SEO и счётчики')
        ->assertDontSee(__('shop.navigation.settings'));
});

it('shows settings and users in the admin navigation, groups in order', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin')
        ->assertOk()
        ->assertSee('Пользователи')
        ->assertSee('SEO и счётчики')
        ->assertSee(__('shop.navigation.settings'));
});

it('denies panel access to inactive users', function () {
    $this->actingAs(User::factory()->admin()->inactive()->create());

    $this->get('/admin')->assertForbidden();
});

it('uses the configured admin path', function () {
    expect(config('shop.admin_path'))->toBe('admin');
});
