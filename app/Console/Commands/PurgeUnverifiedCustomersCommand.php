<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Customers\CustomerRetentionService;
use Illuminate\Console\Command;

class PurgeUnverifiedCustomersCommand extends Command
{
    public const BUDGET_SECONDS = 35;

    protected $signature = 'shop:purge-unverified-customers';

    protected $description = 'Delete customer accounts whose e-mail was never confirmed within the retention period (CA-24)';

    public function handle(CustomerRetentionService $retention): int
    {
        $count = $retention->purgeUnverified(microtime(true) + self::BUDGET_SECONDS);

        $this->info(__('customers.command.purged_unverified', ['count' => $count]));

        return self::SUCCESS;
    }
}
