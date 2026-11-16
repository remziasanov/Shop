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
 * Sent instead of a code when someone registers an address that already has an account (SEC-06): the owner is told to
 * sign in or reset the password. The registration page answers the same way for known and unknown addresses.
 */
class CustomerAccountExistsMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use StripsMarkdownEscapes;

    public int $tries = 3;

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account.mail.exists_subject'));
    }

    public function content(): Content
    {
        $this->stripMarkdownEscapesFromTextPart();

        return new Content(markdown: 'mail.customer-account-exists', with: [
            'loginUrl' => route('customer.login'),
            'resetUrl' => route('customer.password.forgot'),
        ]);
    }
}
