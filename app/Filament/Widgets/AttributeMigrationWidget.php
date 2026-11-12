<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\Catalog\AttributeMigrationState;
use App\Services\Users\PermissionService;
use Filament\Widgets\Widget;

/**
 * Banner «Миграция характеристик не завершена» (FL-04): products still keep characteristics in JSON, there are no
 * filters by them yet. Shows the progress «N из M» while the background job runs.
 */
class AttributeMigrationWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = -9;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.attribute-migration';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(PermissionService::class)->allows($user, 'attributes.update')
            && app(AttributeMigrationState::class)->isPending();
    }

    /**
     * @return array{total: int, done: int}|null
     */
    public function progress(): ?array
    {
        $state = app(AttributeMigrationState::class)->current();

        return $state === null ? null : ['total' => $state['total'], 'done' => $state['done']];
    }
}
