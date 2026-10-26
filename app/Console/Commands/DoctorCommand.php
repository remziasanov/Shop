<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CheckStatus;
use App\Services\System\CheckResult;
use App\Services\System\EnvironmentChecker;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'shop:doctor {--json : Output machine-readable JSON}';

    protected $description = 'Check the server environment (TZ 12.4)';

    public function handle(EnvironmentChecker $checker): int
    {
        $results = $checker->run();
        $hasError = collect($results)->contains(fn (CheckResult $r) => $r->status === CheckStatus::Error);

        if ($this->option('json')) {
            $this->line((string) json_encode(
                array_map(fn (CheckResult $r) => $r->toArray(), $results),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));

            return $hasError ? self::FAILURE : self::SUCCESS;
        }

        $this->table(
            [__('system.columns.status'), __('system.columns.check'), __('system.columns.value'), __('system.columns.hint')],
            array_map(fn (CheckResult $r) => [$r->status->label(), $r->label, $r->value, $r->hint ?? ''], $results),
        );

        return $hasError ? self::FAILURE : self::SUCCESS;
    }
}
