<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Mail\CustomerCodeGuessingMail;
use App\Mail\CustomerCodeMail;
use App\Mail\CustomerEmailChangedMail;
use App\Mail\WholesaleDecisionMail;
use App\Settings\CustomerSettings;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

/**
 * CA-25: Markdown mails in Russian with the code and its validity in the subject and the text, a warning, queued with
 * three attempts.
 */
it('renders the code mail of every purpose with the code, the validity and the warning', function (VerificationPurpose $purpose): void {
    $mail = new CustomerCodeMail($purpose, '482915', 15, 'Анна <b>Тест</b>');

    $html = $mail->render();

    expect($mail->envelope()->subject)->toContain('482915')
        ->and($html)->toContain('482915')
        ->and($html)->toContain('Код действует 15 мин.')
        ->and($html)->toContain('Никому не сообщайте')
        ->and($html)->not->toContain('<b>Тест</b>');
    expect(__('account.mail.code_intro.'.$purpose->value))->not->toBe('account.mail.code_intro.'.$purpose->value);
})->with(VerificationPurpose::cases());

it('is queued, encrypted in the queue payload and tried three times', function (): void {
    $mail = new CustomerCodeMail(VerificationPurpose::Login, '123456', 15);

    expect($mail)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($mail->tries)->toBe(3);
    expect((new CustomerCodeGuessingMail)->tries)->toBe(3)->and((new CustomerEmailChangedMail('a***@example.com'))->tries)->toBe(3);
});

it('uses the texts of the settings page', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->code_mail_warning = 'Особое предупреждение магазина.';
    $settings->code_mail_footer = 'Команда магазина';
    $settings->save();

    $html = (new CustomerCodeMail(VerificationPurpose::Register, '111222', 15))->render();

    expect($html)->toContain('Особое предупреждение магазина.')->toContain('Команда магазина');
});

it('renders the guessing warning and the e-mail change notice without personal data', function (): void {
    $guessing = (new CustomerCodeGuessingMail)->render();
    $changed = (new CustomerEmailChangedMail('n***@example.com'))->render();

    expect($guessing)->toContain('24 часа')
        ->and($changed)->toContain('n***@example.com');
});

/**
 * The plain-text part of the mails is the original text, not the Markdown source: no `\.` and the like. The mails are
 * queued, so the callback is attached in content() (it cannot ride in the serialized mailable).
 */
it('leaves no Markdown escapes in the text part of the customer mails', function (): void {
    $settings = app(CustomerSettings::class);
    $settings->code_mail_warning = 'Никому не сообщайте код. Сотрудники магазина его не спрашивают.';
    $settings->code_mail_footer = 'Магазин «Тест», тел. 8-800-555-35-35.';
    $settings->save();

    $mails = [
        new CustomerCodeMail(VerificationPurpose::Login, '482915', 15, 'Анна-Мария (Тест)'),
        new CustomerCodeGuessingMail,
        new CustomerEmailChangedMail('n***@example.com'),
        new WholesaleDecisionMail('Анна', true, 'Опт. 1', ['Оптовые цены.', 'Оплата по счёту.'], null, 'https://shop.example.com/account'),
        new WholesaleDecisionMail('Анна', false, null, [], 'Нет документов. Подайте заявку ещё раз.', 'https://shop.example.com/account'),
    ];

    foreach ($mails as $mail) {
        Mail::mailer('array')->to('text.part@example.com')->send($mail);

        $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();
        $text = (string) $message->getTextBody();

        // No backslash at all: the escapes (`\.`, `\(`, `\-`) are only for the Markdown source of the HTML part.
        expect($text)->not->toBe('')->and($text)->not->toContain('\\')
            ->and($html)->not->toBe('');
    }

    expect($text)->toContain('Нет документов. Подайте заявку ещё раз.');
});

it('goes through the queue (serialized before it is built) and arrives without escapes', function (): void {
    // QUEUE_CONNECTION=sync in tests: the mailable is serialized into the payload, then built by the "worker".
    Mail::mailer('array')->to('queued.owner@example.com')->queue(new CustomerCodeMail(VerificationPurpose::Register, '135790', 15, 'Анна'));

    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect((string) $message->getTextBody())->toContain('135790')->not->toContain('\\');
});
