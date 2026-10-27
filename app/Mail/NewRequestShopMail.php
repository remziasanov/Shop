<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use App\Services\Notifications\Messages\OrderMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notification to the shop about a new request (NT-03).
 */
class NewRequestShopMail extends Mailable
{
    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.mail.new_subject', [
            'type' => mb_strtolower($this->order->type->label()),
            'number' => $this->order->number,
        ]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.new-request', with: [
            'order' => $this->order->loadMissing('items'),
            'adminUrl' => OrderMessage::adminUrl($this->order),
        ]);
    }
}
