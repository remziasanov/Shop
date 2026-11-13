<?php

declare(strict_types=1);

namespace App\Services\Promo;

/**
 * PM-10: the identity of a guest for the per-customer limit. A salted HMAC (the application key is the secret): a
 * plain hash of a phone number would be recovered by trying every number.
 */
final class PromoCustomerHash
{
    public static function make(?string $email, ?string $phone): ?string
    {
        $email = $email === null ? '' : mb_strtolower(trim($email));
        $phone = $phone === null ? '' : trim($phone);

        if ($email === '' && $phone === '') {
            return null;
        }

        return hash_hmac('sha256', $email.'|'.$phone, (string) config('app.key'));
    }
}
