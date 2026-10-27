<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Content\LegalPageStatus;
use Filament\Widgets\Widget;

/**
 * Reminder for administrators: the privacy / consent pages still hold the template text (D-055).
 */
class LegalTemplatesWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.legal-templates';

    public static function canView(): bool
    {
        return (auth()->user()?->isAdmin() ?? false) && app(LegalPageStatus::class)->pendingTitles() !== [];
    }

    /**
     * @return list<string>
     */
    public function titles(): array
    {
        return app(LegalPageStatus::class)->pendingTitles();
    }
}
