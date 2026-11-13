<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\StripsMarkdownEscapes;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * «Состав заказа изменён» (OE-09): the items after the edit, the difference in the amount and the new total.
 * Totals are kopecks as they were when the edit was saved.
 */
class OrderItemsChangedCustomerMail extends Mailable
{
    use StripsMarkdownEscapes;

    public function __construct(public readonly Order $order, public readonly int $oldTotal, public readonly int $newTotal)
    {
        $this->stripMarkdownEscapesFromTextPart();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('order_edit.mail.subject', [
            'type' => $this->order->type->label(),
            'number' => $this->order->number,
        ]));
    }

    public function content(): Content
    {
        $delta = $this->newTotal - $this->oldTotal;

        return new Content(markdown: 'mail.order-items-changed', with: [
            'order' => $this->order->loadMissing('items'),
            'wasTotal' => Money::format($this->oldTotal),
            'nowTotal' => Money::format($this->newTotal),
            'delta' => $delta === 0 ? null : ($delta > 0 ? '+' : '−').Money::format(abs($delta)),
        ]);
    }
}
