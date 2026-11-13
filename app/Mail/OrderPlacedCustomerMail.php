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
        $related = $this->related();

        if ($related !== null) {
            // MR-11: one letter for both requests of the checkout.
            return new Envelope(subject: __('notifications.mail.placed_subject_many', [
                'numbers' => $this->order->number.' '.__('notifications.mail.and').' '.$related->number,
            ]));
        }

        return new Envelope(subject: __('notifications.mail.placed_subject', [
            'type' => $this->order->type->label(),
            'number' => $this->order->number,
        ]));
    }

    /**
     * The other request of the same checkout (MR-05, MR-11) that this letter also lists; null for a single request.
     */
    private function related(): ?Order
    {
        $related = $this->order->related_order_id !== null ? Order::query()->find($this->order->related_order_id) : null;

        return $related !== null && $related->customer_email === $this->order->customer_email ? $related : null;
    }

    public function content(): Content
    {
        $sales = app(SalesSettings::class);
        $thanks = match ($this->order->type) {
            OrderType::Order => $sales->thanks_order_text,
            OrderType::Reservation => $sales->thanks_reservation_text,
            OrderType::Inquiry => $sales->thanks_inquiry_text,
        };

        $related = $this->related();

        return new Content(markdown: 'mail.order-placed', with: [
            'order' => $this->order->loadMissing('items'),
            'requests' => $related === null ? [$this->order] : [$this->order, $related->loadMissing('items')],
            'thanks' => filled($thanks) ? $thanks : __('notifications.mail.default_thanks.'.$this->order->type->value),
            'general' => app(GeneralSettings::class),
        ]);
    }
}
