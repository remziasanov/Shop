<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PhoneVerification\BalanceMonitor;
use App\Services\PhoneVerification\PhoneVerificationService;
use Illuminate\Console\Command;

class PhoneVerificationBalanceCommand extends Command
{
    protected $signature = 'shop:phone-verification-balance';

    protected $description = 'Check the phone verification provider balance and warn the shop when it is low (PV-09)';

    public function handle(BalanceMonitor $monitor, PhoneVerificationService $service): int
    {
        if (! $service->isEnabled()) {
            $this->line(__('phone_verification.command.disabled'));

            return self::SUCCESS;
        }

        $balance = $monitor->check();

        $this->line($balance === null ? __('phone_verification.command.unknown') : __('phone_verification.command.balance', ['balance' => $balance]));

        return self::SUCCESS;
    }
}
