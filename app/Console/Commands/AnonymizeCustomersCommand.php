<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Customers\CustomerRetentionService;
use Illuminate\Console\Command;

class AnonymizeCustomersCommand extends Command
{
    /** Time budget in seconds: every job stays under 40 s on shared hosting (TZ section 2). */
    public const BUDGET_SECONDS = 35;

    protected $signature = 'shop:anonymize-customers';

    protected $description = 'Anonymize customers whose account deletion request has passed the cancel period (CA-16, CA-17)';

    public function handle(CustomerRetentionService $retention): int
    {
        $count = $retention->anonymizeDue(microtime(true) + self::BUDGET_SECONDS);

        $this->info(__('customers.command.anonymized', ['count' => $count]));

        return self::SUCCESS;
    }
}
