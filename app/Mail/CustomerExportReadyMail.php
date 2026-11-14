<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\StripsMarkdownEscapes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a background export of the order history is ready (CX-06, optional by the setting): the link works for the
 * signed-in owner of the file only. Queued (database queue, three attempts).
 */
class CustomerExportReadyMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use StripsMarkdownEscapes;

    public int $tries = 3;

    public function __construct(
        public readonly string $name,
        public readonly string $format,
        public readonly string $url,
        public readonly string $until,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('customer_export.mail.subject'));
    }

    public function content(): Content
    {
        $this->stripMarkdownEscapesFromTextPart();

        return new Content(markdown: 'mail.customer-export-ready', with: [
            'name' => $this->name,
            'format' => $this->format,
            'url' => $this->url,
            'until' => $this->until,
        ]);
    }
}
