<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\OrderItemsChangedCustomerMail;
use App\Models\Order;
use App\Services\Notifications\MailDeliveryException;
use App\Support\PiiMask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * «Состав заказа изменён» to the customer (OE-09), queued after the edit commits. A failure is retried on its own and
 * never touches the saved edit.
 */
class SendOrderChangedMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  int  $oldTotal  kopecks before the edit
     * @param  int  $newTotal  kopecks after the edit
     */
    public function __construct(
        public readonly int $orderId,
        public readonly int $oldTotal,
        public readonly int $newTotal,
    ) {
        $this->afterCommit();
    }

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

        if ($order === null || blank($order->customer_email)) {
            return;
        }

        $recipient = (string) $order->customer_email;

        try {
            Mail::to($recipient)->send(new OrderItemsChangedCustomerMail($order, $this->oldTotal, $this->newTotal));
        } catch (TransportExceptionInterface|RfcComplianceException $e) {
            // Transport exceptions quote the recipient: rethrow a sanitized one, without `previous` (NF-15).
            throw new MailDeliveryException(PiiMask::scrubEmails(str_replace($recipient, PiiMask::email($recipient), $e::class.': '.$e->getMessage())));
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('Order changed email failed', [
            'order_id' => $this->orderId,
            'exception' => $e::class,
            'message' => PiiMask::scrubEmails($e->getMessage()),
        ]);
    }
}
