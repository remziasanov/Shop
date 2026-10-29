<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Rules\CanonicalEmail;

/**
 * The one canonical spelling of an e-mail address (CA-05, CA-06, CA-07, CA-14).
 *
 * Why: `utf8mb4_unicode_ci` on MySQL is accent- and case-insensitive, so `victim@gmail.com` equals `victim@gmáil.com`
 * in a `WHERE` clause or a unique index, while the mailer delivers the second one to the punycode domain of a
 * different owner. Every address that is stored, looked up or compared is therefore reduced to pure ASCII first:
 * trimmed, lower-cased, an ASCII-only local part and a punycode domain. Anything else is refused (null).
 */
final class EmailNormalizer
{
    /**
     * @return string|null the canonical address, or null when it cannot be canonicalised
     */
    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);
        $at = strrpos($email, '@');

        if ($at === false || $at === 0 || $at === strlen($email) - 1) {
            return null;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        // Local part: printable ASCII only (no controls, no spaces, no multibyte look-alikes).
        if (preg_match('/^[\x21-\x7E]+$/', $local) !== 1) {
            return null;
        }

        $domain = mb_strtolower($domain);

        if (! function_exists('idn_to_ascii')) {
            return null;
        }

        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        if (! is_string($ascii) || $ascii === '' || preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $ascii) !== 1) {
            return null;
        }

        return strtolower($local).'@'.$ascii;
    }

    /**
     * The canonical address, or the trimmed lower-cased input when it cannot be canonicalised. Used by requests, which
     * hand the value on to {@see CanonicalEmail} for the refusal.
     */
    public static function normalizeOrLower(string $email): string
    {
        return self::normalize($email) ?? mb_strtolower(trim($email));
    }
}
