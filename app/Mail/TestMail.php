<?php

declare(strict_types=1);

namespace App\Mail;

use App\Settings\GeneralSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Test message from the notification settings page (NT-04).
 */
class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.mail.test_subject'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.test', with: ['shopName' => app(GeneralSettings::class)->shop_name]);
    }
}
