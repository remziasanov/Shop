<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\VerificationPurpose;
use App\Settings\CustomerSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The mail with a one-time code (CA-25): confirm the address, sign in, reset the password, change the e-mail, delete
 * the account. The code and the validity are in the subject and the text. Queued (database queue, three attempts) and
 * encrypted in the queue payload, so a plain code never sits in the `jobs` / `failed_jobs` tables.
 */
class CustomerCodeMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly VerificationPurpose $purpose,
        public readonly string $code,
        public readonly int $ttlMinutes,
        public readonly ?string $name = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('account.mail.code_subject.'.$this->purpose->value, ['code' => $this->code]));
    }

    public function content(): Content
    {
        $settings = app(CustomerSettings::class);

        return new Content(markdown: 'mail.customer-code', with: [
            'purpose' => $this->purpose,
            'code' => $this->code,
            'ttlMinutes' => $this->ttlMinutes,
            'name' => $this->name,
            'warning' => $settings->code_mail_warning,
            'footer' => $settings->code_mail_footer,
        ]);
    }
}
