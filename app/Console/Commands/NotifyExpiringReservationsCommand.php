<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Jobs\SendTelegramNotification;
use App\Models\Order;
use App\Services\Customers\CustomerNotificationService;
use App\Services\Notifications\JobFanOut;
use App\Services\Notifications\TelegramNotifier;
use App\Settings\NotificationSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class NotifyExpiringReservationsCommand extends Command
{
    /** Hours before the end of the term when the shop is warned. */
    public const WINDOW_HOURS = 6;

    /** Time budget in seconds (the scheduler runs this hourly; a run must stay under a minute). */
    public const BUDGET_SECONDS = 45;

    protected $signature = 'shop:notify-expiring-reservations';

    protected $description = 'Telegram warning about reservations that expire within 6 hours, once per reservation (AD-10)';

    public function handle(NotificationSettings $settings, TelegramNotifier $telegram, CustomerNotificationService $cabinet): int
    {
        // CA-18: the cabinet notification of the customer does not depend on the Telegram settings of the shop.
        try {
            $cabinet->notifyExpiringReservations(microtime(true) + 20);
        } catch (Throwable $e) {
            report($e);
        }

        if (! $settings->telegram_enabled || ! $settings->notify_reservation_expiring || ! $telegram->isConfigured()) {
            $this->info(__('admin_orders.command.expiring_notified', ['count' => 0]));

            return self::SUCCESS;
        }

        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $notified = 0;
        $lastId = 0;

        do {
            $ids = Order::query()
                ->expiringWithin(self::WINDOW_HOURS)
                ->whereNull('expiring_notified_at')
                ->where('orders.id', '>', $lastId)
                ->orderBy('orders.id')
                ->limit(50)
                ->pluck('id');

            foreach ($ids as $id) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }

                $lastId = (int) $id;

                try {
                    // Claim and queue in one transaction (the database queue shares the connection): if queueing
                    // fails the claim is rolled back and the next run retries; a concurrent run skips a claimed order.
                    $claimed = DB::transaction(function () use ($id): bool {
                        $claimed = Order::query()
                            ->whereKey($id)
                            ->whereNull('expiring_notified_at')
                            ->whereIn('status', [OrderStatus::New->value, OrderStatus::Reserved->value])
                            ->update(['expiring_notified_at' => now()]);

                        if ($claimed !== 1) {
                            return false;
                        }

                        // Nothing queued (dispatch failed or the unique job is already held): do not keep the claim,
                        // otherwise the warning would be marked as sent and never go out.
                        if (JobFanOut::dispatch([new SendTelegramNotification((int) $id, SendTelegramNotification::EXPIRING)]) < 1) {
                            throw new RuntimeException('Expiring-reservation notification was not queued for order '.$id);
                        }

                        return true;
                    });

                    if ($claimed) {
                        $notified++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } while ($ids->count() === 50);

        $this->info(__('admin_orders.command.expiring_notified', ['count' => $notified]));

        return self::SUCCESS;
    }
}
