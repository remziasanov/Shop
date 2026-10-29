<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Customers\VerificationCodeService;
use Illuminate\Console\Command;

class PurgeCustomerCodesCommand extends Command
{
    protected $signature = 'shop:purge-customer-codes';

    protected $description = 'Delete expired e-mail codes of customers (CA-04)';

    public function handle(VerificationCodeService $codes): int
    {
        $count = $codes->purgeExpired(1, microtime(true) + 35);

        $this->info(__('customers.command.purged_codes', ['count' => $count]));

        return self::SUCCESS;
    }
}
