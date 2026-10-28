<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\ImportStatus;
use App\Filament\Resources\Imports\ImportResource;
use App\Models\Import;
use App\Services\Dashboard\DashboardStats;
use App\Services\Users\PermissionService;
use Filament\Widgets\Widget;

/**
 * AD-10: the last import and its outcome.
 */
class LastImportWidget extends Widget
{
    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        return app(PermissionService::class)->allowsCurrent('imports.view');
    }

    protected string $view = 'filament.widgets.last-import';

    public function import(): ?Import
    {
        return app(DashboardStats::class)->lastImport();
    }

    public function badgeColor(ImportStatus $status): string
    {
        return match ($status) {
            ImportStatus::Completed => 'success',
            ImportStatus::CompletedWithErrors => 'warning',
            ImportStatus::Failed => 'danger',
            default => 'gray',
        };
    }

    public function url(Import $import): string
    {
        return ImportResource::getUrl('view', ['record' => $import]);
    }

    public function listUrl(): string
    {
        return ImportResource::getUrl('index');
    }
}
