<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PHP security baseline (D-106)
|--------------------------------------------------------------------------
| Lowest patched release of each PHP branch. A runtime below it is reported by shop:doctor and the
| admin "System" page, fails CI and blocks the deploy. Plain array without env() on purpose: CI and the
| deploy workflow read this file without booting Laravel (.github/scripts/php_security_check.php).
| Branches missing here (newer than the list) are accepted.
|
| 2026-10-21: CVE-2026-109618, CVE-2026-109619 ("PHP2Shell", unauthenticated remote code execution).
*/

return [

    'patched_minimum' => [
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ],

    'advisory' => 'CVE-2026-109618, CVE-2026-109619',

];
