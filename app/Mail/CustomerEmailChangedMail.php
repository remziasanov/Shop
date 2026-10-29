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
 * Sent to the OLD address after a confirmed e-mail change (CA-08). The new address is masked.
 */
class CustomerEmailChangedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use StripsMarkdownEscapes;

    public int $tries = 3;

    public function __construct(public readonly string $maskedNewEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account.mail.email_changed_subject'));
    }

    public function content(): Content
    {
        $this->stripMarkdownEscapesFromTextPart();

        return new Content(markdown: 'mail.customer-email-changed', with: ['newEmail' => $this->maskedNewEmail]);
    }
}
