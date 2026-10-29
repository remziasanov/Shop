<?php

declare(strict_types=1);

namespace App\Services\Customers;

/**
 * A validated registration (CA-02): the e-mail is in canonical form ({@see EmailNormalizer}), the phone is normalized to +7XXXXXXXXXX.
 */
final class RegistrationData
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $password,
        public readonly string $ip,
        /** Random token kept in the registering browser's session; the code is usable only from that session. */
        public readonly string $bindingToken = '',
    ) {}
}
