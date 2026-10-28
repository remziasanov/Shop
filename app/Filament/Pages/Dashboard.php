<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\CronWarningWidget;
use App\Filament\Widgets\LastImportWidget;
use App\Filament\Widgets\LegalTemplatesWidget;
use App\Filament\Widgets\ProductsStatsWidget;
use App\Filament\Widgets\RequestsChartWidget;
use App\Filament\Widgets\RequestsStatsWidget;
use App\Models\User;
use App\Services\Users\PermissionService;
use Filament\Pages\Dashboard as BaseDashboard;

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
            ProductsStatsWidget::class,
            RequestsChartWidget::class,
            LastImportWidget::class,
        ];
    }
}
