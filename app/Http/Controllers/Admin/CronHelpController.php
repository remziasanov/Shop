<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Services\Users\PermissionService;
use Illuminate\Contracts\View\View;

/**
 * Cron setup instructions (AD-11): the two lines to add in the hosting panel.
 */
final class CronHelpController
{
    /** Typical PHP binary on Beget; the actual version depends on the site settings. */
    public const SUGGESTED_PHP = '/usr/local/php/cgi/8.3/bin/php';

    public function __invoke(PermissionService $permissions): View
    {
        // Linked from the dashboard warning (dashboard.view) and from the system status page (system.view).
        $user = auth()->user();
        abort_unless($user instanceof User && $permissions->allowsAny($user, 'dashboard.view', 'system.view'), 403);

        $base = base_path();

        return view('admin.help.cron', [
            'scheduler' => '* * * * * cd '.$base.' && '.self::SUGGESTED_PHP.' artisan schedule:run >> /dev/null 2>&1',
            'queue' => '* * * * * cd '.$base.' && '.self::SUGGESTED_PHP.' artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1',
            'panelUrl' => url((string) config('shop.admin_path')),
        ]);
    }
}
