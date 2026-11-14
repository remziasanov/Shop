<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telegram\TelegramPruner;
use Illuminate\Console\Command;

class TelegramPruneCommand extends Command
{
    protected $signature = 'shop:telegram-prune';

    protected $description = 'Delete old Telegram updates, used and expired link tokens, old messages and abandoned accounts (TG-12)';

    public function handle(TelegramPruner $pruner): int
    {
        $result = $pruner->run();

        $this->info(__('telegram.command.pruned', $result));

        return self::SUCCESS;
    }
}
