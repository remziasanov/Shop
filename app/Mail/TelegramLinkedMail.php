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
 * Sent to the owner of an account after a Telegram was linked to it (TG-07): if it was not them, they disconnect it.
 */
class TelegramLinkedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use StripsMarkdownEscapes;

    public int $tries = 3;

    public function __construct(public readonly string $telegram, public readonly bool $staff) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('telegram.mail.subject'));
    }

    public function content(): Content
    {
        $this->stripMarkdownEscapesFromTextPart();

        return new Content(markdown: 'mail.telegram-linked', with: ['telegram' => $this->telegram, 'staff' => $this->staff]);
    }
}
