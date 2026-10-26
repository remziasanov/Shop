<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\System\CheckResult;
use App\Services\System\EnvironmentChecker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class SystemStatus extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 100;

    protected static ?string $slug = 'system';

    protected string $view = 'filament.pages.system-status';

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.system');
    }

    public static function getNavigationLabel(): string
    {
        return __('system.title');
    }

    public function getTitle(): string
    {
        return __('system.title');
    }

    /**
     * @return list<CheckResult>
     */
    public function getResults(): array
    {
        return app(EnvironmentChecker::class)->run();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('system.refresh'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->action(fn () => null),
        ];
    }
}
