<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\OrderType;
use App\Mail\Concerns\StripsMarkdownEscapes;
use App\Models\Order;
use App\Settings\GeneralSettings;
use App\Settings\SalesSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Confirmation to the customer: number, items, totals, delivery, reservation term (SF-47, NT-03).
 */
class OrderPlacedCustomerMail extends Mailable
{
    use StripsMarkdownEscapes;

    public function __construct(public readonly Order $order)
    {
        $this->stripMarkdownEscapesFromTextPart();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.mail.placed_subject', [
            'type' => $this->order->type->label(),
            'number' => $this->order->number,
        ]));
    }

    public function content(): Content
    {
        $sales = app(SalesSettings::class);
        $thanks = match ($this->order->type) {
            OrderType::Order => $sales->thanks_order_text,
            OrderType::Reservation => $sales->thanks_reservation_text,
            OrderType::Inquiry => $sales->thanks_inquiry_text,
        };

        return new Content(markdown: 'mail.order-placed', with: [
            'order' => $this->order->loadMissing('items'),
            'thanks' => filled($thanks) ? $thanks : __('notifications.mail.default_thanks.'.$this->order->type->value),
            'general' => app(GeneralSettings::class),
        ]);
    }
}
