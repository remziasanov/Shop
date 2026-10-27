<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Jobs\SendOrderNotifications;
use App\Mail\OrderStatusChangedCustomerMail;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Wires request events to the notification jobs (NT-01): listeners only queue work and never throw, so
 * a request is created or changed regardless of the state of mail or Telegram.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Mail templates are Markdown: customer text must pass through these (raw output, already HTML-safe).
        Blade::directive('md', fn (string $expression): string => "<?php echo \\App\\Support\\MarkdownEscaper::escape({$expression}); ?>");
        Blade::directive('mdi', fn (string $expression): string => "<?php echo \\App\\Support\\MarkdownEscaper::inline({$expression}); ?>");

        Event::listen(OrderCreated::class, function (OrderCreated $event): void {
            self::safely(fn () => SendOrderNotifications::dispatch($event->order->id, SendOrderNotifications::EVENT_CREATED)->afterCommit());
        });

        Event::listen(OrderStatusChanged::class, function (OrderStatusChanged $event): void {
            self::safely(function () use ($event): void {
                if ($event->to === OrderStatus::Expired && $event->bySystem) {
                    SendOrderNotifications::dispatch($event->order->id, SendOrderNotifications::EVENT_EXPIRED, $event->to->value)->afterCommit();

                    return;
                }

                if (OrderStatusChangedCustomerMail::shouldNotify($event->to)) {
                    SendOrderNotifications::dispatch($event->order->id, SendOrderNotifications::EVENT_STATUS_CHANGED, $event->to->value)->afterCommit();
                }
            });
        });
    }

    private static function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
