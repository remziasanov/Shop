<?php

declare(strict_types=1);

use App\Services\Customers\EmailNormalizer;

/**
 * The canonical ASCII spelling of an e-mail address (CA-05…CA-07, CA-14): see EmailNormalizer.
 */
it('lower-cases and trims an address', function (): void {
    expect(EmailNormalizer::normalize("  Ivan.Test@Example.COM \n"))->toBe('ivan.test@example.com');
});

it('converts an international domain to punycode', function (): void {
    expect(EmailNormalizer::normalize('victim@gmáil.com'))->toBe('victim@xn--gmil-6na.com')
        ->and(EmailNormalizer::normalize('Иван@почта.рф'))->toBeNull()
        ->and(EmailNormalizer::normalize('ivan@ПОЧТА.рф'))->toBe('ivan@xn--80a1acny.xn--p1ai');
});

it('never makes a look-alike equal to the real address', function (): void {
    expect(EmailNormalizer::normalize('victim@gmáil.com'))->not->toBe(EmailNormalizer::normalize('victim@gmail.com'));
});

it('refuses a non-ASCII local part', function (string $address): void {
    expect(EmailNormalizer::normalize($address))->toBeNull();
})->with(['accent' => 'victím@gmail.com', 'cyrillic' => 'иван@example.com', 'full-width' => 'ｖictim@gmail.com']);

it('refuses what is not an address', function (string $address): void {
    expect(EmailNormalizer::normalize($address))->toBeNull();
})->with([
    'empty' => '',
    'no at' => 'victim.gmail.com',
    'no local part' => '@gmail.com',
    'no domain' => 'victim@',
    'space inside' => 'vic tim@gmail.com',
    'bad domain characters' => 'victim@gm ail.com',
    'underscore domain' => 'victim@gm_ail.com',
]);

it('is idempotent and null-safe', function (): void {
    $once = EmailNormalizer::normalize('Victim@GMÁIL.com');

    expect($once)->not->toBeNull()
        ->and(EmailNormalizer::normalize($once))->toBe($once)
        ->and(EmailNormalizer::normalize(null))->toBeNull();
});

it('keeps the typed value for the validation message when there is no canonical form', function (): void {
    expect(EmailNormalizer::normalizeOrLower('  ИВАН@Example.com '))->toBe('иван@example.com')
        ->and(EmailNormalizer::normalizeOrLower('Ivan@Example.com'))->toBe('ivan@example.com');
});
