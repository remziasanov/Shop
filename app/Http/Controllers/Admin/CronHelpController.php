<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Contracts\View\View;

/**
 * Cron setup instructions (AD-11): the two lines to add in the hosting panel.
 */
final class CronHelpController
{
    /** Typical PHP binary on Beget; the actual version depends on the site settings. */
    public const SUGGESTED_PHP = '/usr/local/php/cgi/8.3/bin/php';

    public function __invoke(): View
    {
        $base = base_path();

        return view('admin.help.cron', [
            'scheduler' => '* * * * * cd '.$base.' && '.self::SUGGESTED_PHP.' artisan schedule:run >> /dev/null 2>&1',
            'queue' => '* * * * * cd '.$base.' && '.self::SUGGESTED_PHP.' artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1',
            'panelUrl' => url((string) config('shop.admin_path')),
        ]);
    }
}
