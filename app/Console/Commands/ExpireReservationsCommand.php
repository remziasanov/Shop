<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Orders\NewRequestsCounter;
use App\Services\Orders\OrderService;
use Illuminate\Console\Command;

class ExpireReservationsCommand extends Command
{
    /** Time budget in seconds: the scheduler runs the command every 5 minutes and a run must stay under a minute. */
    public const BUDGET_SECONDS = 45;

    protected $signature = 'shop:expire-reservations';

    protected $description = 'Expire reservations whose term has passed and return their stock (SF-53)';

    public function handle(OrderService $orders, NewRequestsCounter $counter): int
    {
        $expired = $orders->expireReservations(microtime(true) + self::BUDGET_SECONDS);

        if ($expired > 0) {
            $counter->forget();
        }

        $this->info(__('admin_orders.command.expired', ['count' => $expired]));

        return self::SUCCESS;
    }
}
