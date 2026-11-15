<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\StripsMarkdownEscapes;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A warning to the shop about the phone verification provider (PV-05, PV-09): a failure, an empty or low balance.
 * Sent by SendPhoneVerificationAlert (the queued part), so it is not queued itself. Carries no phone numbers.
 */
class PhoneVerificationAlertMail extends Mailable
{
    use StripsMarkdownEscapes;

    public function __construct(public readonly string $subjectLine, public readonly string $body)
    {
        $this->stripMarkdownEscapesFromTextPart();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.phone-verification-alert', with: ['title' => $this->subjectLine, 'body' => $this->body]);
    }
}
