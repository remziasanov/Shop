<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\Cleanup;
use Illuminate\Console\Command;

class CleanupCommand extends Command
{
    /** Time budget in seconds: a scheduled run must stay under a minute (TZ section 8). */
    public const BUDGET_SECONDS = 45;

    protected $signature = 'shop:cleanup {--exports : Only remove expired export files (staff exports and customer exports)}';

    protected $description = 'Delete old carts, import and export files and abandoned uploads (TZ section 8)';

    public function handle(Cleanup $cleanup): int
    {
        $summary = $cleanup->run(microtime(true) + self::BUDGET_SECONDS, (bool) $this->option('exports'));

        $keys = $this->option('exports')
            ? ['exports', 'customer_exports']
            : ['carts', 'imports', 'temp_files', 'exports', 'customer_exports', 'livewire', 'audit_log', 'customer_notifications'];

        foreach ($keys as $key) {
            $this->line(__('cleanup.'.$key, ['count' => $summary[$key]]));
        }

        if ($summary['skipped']) {
            $this->warn(__('cleanup.skipped'));
        }

        return self::SUCCESS;
    }
}
