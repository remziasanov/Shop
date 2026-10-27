<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\System\Heartbeat;
use Filament\Widgets\Widget;

/**
 * AD-11: shown to everyone in the panel while the scheduler heartbeat is older than 5 minutes.
 */
class CronWarningWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -20;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.cron-warning';

    public static function canView(): bool
    {
        return app(Heartbeat::class)->isStale(5);
    }
}
