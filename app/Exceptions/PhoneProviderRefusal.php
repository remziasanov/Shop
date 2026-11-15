<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider answered, but did not accept the request: zero balance (`$insufficientBalance`) or a refused number /
 * malformed answer. It is NOT a failure (PV-05): it always blocks, because an attacker could drain the balance within the
 * limits and thereby switch the check off.
 */
class PhoneProviderRefusal extends RuntimeException
{
    public function __construct(public readonly bool $insufficientBalance, string $message = '')
    {
        parent::__construct($message !== '' ? $message : ($insufficientBalance ? 'insufficient balance' : 'refused'));
    }
}
