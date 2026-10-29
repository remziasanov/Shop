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
 * «Были попытки угадать код» (CA-12): the owner of an account is told that code entry was blocked for 24 hours after
 * many wrong codes. Carries no code and no IP address.
 */
class CustomerCodeGuessingMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use StripsMarkdownEscapes;

    public int $tries = 3;

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account.mail.guessing_subject'));
    }

    public function content(): Content
    {
        $this->stripMarkdownEscapesFromTextPart();

        return new Content(markdown: 'mail.customer-code-guessing');
    }
}
