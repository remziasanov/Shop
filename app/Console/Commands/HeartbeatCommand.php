<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\Heartbeat;
use Illuminate\Console\Command;

class HeartbeatCommand extends Command
{
    protected $signature = 'shop:heartbeat';

    protected $description = 'Record the scheduler heartbeat timestamp (AD-11)';

    public function handle(Heartbeat $heartbeat): int
    {
        $heartbeat->touch();

        return self::SUCCESS;
    }
}
