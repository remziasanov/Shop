<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PhoneProviderFailureKind;
use RuntimeException;

/**
 * A provider failure in the sense of PV-05 (network error, timeout, 5xx, authorization error) — the only case in which the
 * owner's «при сбое провайдера» setting may skip a verification. The message never contains the credentials.
 */
class PhoneProviderFailure extends RuntimeException
{
    public function __construct(public readonly PhoneProviderFailureKind $kind, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $kind->value);
    }
}
