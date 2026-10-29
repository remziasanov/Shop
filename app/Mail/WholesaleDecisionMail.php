<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the customer when the wholesale application is decided (CG-23): the group and what became available, or the
 * reason and how to apply again. Queued (database queue, three attempts).
 */
class WholesaleDecisionMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @param  list<string>  $available  what the granted group offers (approved only)
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $approved,
        public readonly ?string $groupName,
        public readonly array $available,
        public readonly ?string $reason,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->approved ? __('wholesale.mail.approved_subject') : __('wholesale.mail.rejected_subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.wholesale-decision', with: [
            'name' => $this->name,
            'approved' => $this->approved,
            'groupName' => $this->groupName,
            'available' => $this->available,
            'reason' => $this->reason,
            'url' => $this->url,
        ]);
    }
}
