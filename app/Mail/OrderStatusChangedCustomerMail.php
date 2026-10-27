<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Status change notice to the customer; only for the statuses listed in {@see self::NOTIFIABLE} (TZ section 9).
 */
class OrderStatusChangedCustomerMail extends Mailable
{
    /** @var list<OrderStatus> */
    public const NOTIFIABLE = [OrderStatus::Confirmed, OrderStatus::Ready, OrderStatus::Cancelled, OrderStatus::Reserved];

    public function __construct(public readonly Order $order, public readonly OrderStatus $status) {}

    public static function shouldNotify(OrderStatus $status): bool
    {
        return in_array($status, self::NOTIFIABLE, true);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.mail.status_subject', [
            'type' => $this->order->type->label(),
            'number' => $this->order->number,
            'status' => $this->statusLabel(),
        ]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.order-status-changed', with: [
            'order' => $this->order->loadMissing('items'),
            'statusLabel' => $this->statusLabel(),
        ]);
    }

    private function statusLabel(): string
    {
        return $this->status->label($this->order->type);
    }
}
