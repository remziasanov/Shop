<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Mail\CustomerCodeGuessingMail;
use App\Mail\CustomerCodeMail;
use App\Mail\CustomerEmailChangedMail;
use App\Settings\CustomerSettings;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

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
