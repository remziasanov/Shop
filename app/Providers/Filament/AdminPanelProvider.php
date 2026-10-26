<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Livewire\Admin\PriceRecalcBanner;
use App\Settings\GeneralSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Throwable;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path((string) config('shop.admin_path'))
            ->login()
            ->passwordReset()
            ->brandName(fn (): string => $this->brandName())
            ->colors([
                'primary' => Color::Blue,
            ])
            // Order of groups per AD-03.
            ->navigationGroups([
                __('shop.navigation.sales'),
                __('shop.navigation.catalog'),
                __('shop.navigation.prices'),
                __('shop.navigation.content'),
                __('shop.navigation.settings'),
                __('shop.navigation.system'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->pages([Dashboard::class])
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            // PR-05: price recalculation progress above the page content.
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => Blade::render('@livewire($banner)', ['banner' => PriceRecalcBanner::class]))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private function brandName(): string
    {
        try {
            return app(GeneralSettings::class)->shop_name;
        } catch (Throwable) {
            // Settings are not migrated yet (fresh install, CLI before migrate).
            return (string) config('app.name');
        }
    }
}
