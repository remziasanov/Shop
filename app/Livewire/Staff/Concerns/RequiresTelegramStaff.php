<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Concerns;

use App\Models\User;
use App\Services\Telegram\MiniApp\StaffSessionService;
use App\Services\Users\PermissionService;

/**
 * Livewire update requests do not pass through the route middleware of the page, so every «Рабочее место» component asks
 * again, on mount and on every later request (TS-02, TS-07, NF-54): a valid `telegram_staff` session, still linked, active
 * and allowed to view requests. The staff member always comes from the guard `telegram_staff` explicitly, never from the
 * default guard.
 */
trait RequiresTelegramStaff
{
    public function bootRequiresTelegramStaff(): void
    {
        $this->staffUser();
    }

    protected function staffUser(): User
    {
        $user = app(StaffSessionService::class)->valid(app('session.store'));

        abort_unless($user instanceof User, 403, __('telegram_staff.workplace.denied_text'));

        return $user;
    }

    protected function canUpdate(User $user): bool
    {
        return app(PermissionService::class)->allows($user, 'orders.update');
    }
}
