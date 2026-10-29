<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\CronWarningWidget;
use App\Filament\Widgets\LastImportWidget;
use App\Filament\Widgets\LegalTemplatesWidget;
use App\Filament\Widgets\NewCustomersWidget;
use App\Filament\Widgets\ProductsStatsWidget;
use App\Filament\Widgets\RequestsChartWidget;
use App\Filament\Widgets\RequestsStatsWidget;
use App\Filament\Widgets\WholesaleApplicationsWidget;
use App\Models\User;
use App\Services\Users\PermissionService;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Admin dashboard (AD-10, AD-11): requests, reservations, catalog health, import status and the cron warning.
 */
class Dashboard extends BaseDashboard
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, 'dashboard.view');
    }

    /**
     * RB-13: the panel home is the dashboard, but a role without `dashboard.view` must not land on a 403 after
     * signing in. Such a user is sent to the first section the navigation offers; with no section at all the 403 stays.
     */
    public function mountCanAuthorizeAccess(): void
    {
        if (! static::canAccess()) {
            $url = $this->firstAccessibleUrl();

            abort_if($url === null, 403);

            throw new HttpResponseException(redirect()->to($url));
        }
    }

    public function getTitle(): string
    {
        return __('dashboard.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('dashboard.title');
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    public function getWidgets(): array
    {
        return [
            CronWarningWidget::class,
            LegalTemplatesWidget::class,
            RequestsStatsWidget::class,
            NewCustomersWidget::class,
            WholesaleApplicationsWidget::class,
            ProductsStatsWidget::class,
            RequestsChartWidget::class,
            LastImportWidget::class,
        ];
    }

    /**
     * URL of the first navigation item of this panel (the navigation only holds what the user may open).
     */
    private function firstAccessibleUrl(): ?string
    {
        $panelUrl = rtrim((string) url((string) config('shop.admin_path')), '/');
        $self = static::getUrl();

        foreach (Filament::getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $url = $item->getUrl();

                if (is_string($url) && $url !== $self && str_starts_with($url, $panelUrl.'/')) {
                    return $url;
                }
            }
        }

        return null;
    }
}
