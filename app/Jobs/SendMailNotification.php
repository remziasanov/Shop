<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Mail\NewRequestShopMail;
use App\Mail\OrderPlacedCustomerMail;
use App\Mail\OrderStatusChangedCustomerMail;
use App\Models\Order;
use App\Support\PiiMask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * One email to one recipient (NT-01): retried on its own, independent of the other channels.
 */
class SendMailNotification implements ShouldQueue
{
    use Queueable;

    public const CUSTOMER_PLACED = 'customer_placed';

    public const CUSTOMER_STATUS = 'customer_status';

    public const SHOP_NEW = 'shop_new';

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  ?string  $to  recipient of shop emails; customer emails go to the order's address
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $kind,
        public readonly ?string $to = null,
        public readonly ?string $status = null,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        $order = Order::query()->find($this->orderId);

        if ($order === null) {
            return;
        }

        $recipient = $this->kind === self::SHOP_NEW ? $this->to : $order->customer_email;

        if (blank($recipient)) {
            return;
        }

        $mailable = match ($this->kind) {
            self::CUSTOMER_PLACED => new OrderPlacedCustomerMail($order),
            self::SHOP_NEW => new NewRequestShopMail($order),
            self::CUSTOMER_STATUS => new OrderStatusChangedCustomerMail($order, OrderStatus::from((string) $this->status)),
            default => null,
        };

        if ($mailable !== null) {
            Mail::to($recipient)->send($mailable);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Email notification failed', [
            'order_id' => $this->orderId,
            'kind' => $this->kind,
            'to' => PiiMask::email($this->to),
            'exception' => $e::class,
        ]);
    }
}
