<?php

declare(strict_types=1);

namespace App\Services\Customers;

/**
 * A validated registration (CA-02): the e-mail is trimmed and lower-cased, the phone is normalized to +7XXXXXXXXXX.
 */
final class RegistrationData
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $password,
        public readonly string $ip,
    ) {}
}
