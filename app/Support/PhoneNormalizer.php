<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Russian phone numbers to the canonical +7XXXXXXXXXX form (SF-44).
 */
final class PhoneNormalizer
{
    /**
     * '8 (999) 123-45-67', '+7 999 1234567', '9991234567' => '+79991234567'; null when it is not a valid number.
     */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $input) ?? '';

        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        }

        // 10 national digits; the first one is a geographic (3, 4, 8) or mobile (9) code.
        if (! preg_match('/^[3489]\d{9}$/', $digits)) {
            return null;
        }

        return '+7'.$digits;
    }
}
