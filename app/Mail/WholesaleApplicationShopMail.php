<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\StripsMarkdownEscapes;
use App\Models\WholesaleApplication;
use App\Services\Notifications\Messages\WholesaleApplicationMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Notification to the shop about a new wholesale application (CG-21). Sent by SendWholesaleApplicationNotification,
 * which is the queued part, so this mailable is not queued itself.
 */
class WholesaleApplicationShopMail extends Mailable
{
    use StripsMarkdownEscapes;

    /**
     * @param  list<string>  $warnings  duplicate-INN warnings (CG-24)
     */
    public function __construct(public readonly WholesaleApplication $application, public readonly array $warnings = [])
    {
        $this->stripMarkdownEscapesFromTextPart();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('wholesale.mail.shop_subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.wholesale-application', with: [
            'application' => $this->application,
            'warnings' => $this->warnings,
            'adminUrl' => WholesaleApplicationMessage::adminUrl($this->application),
        ]);
    }
}
