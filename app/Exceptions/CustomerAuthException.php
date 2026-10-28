<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A customer-account action failed in a way the customer is told about (wrong code, wrong password, blocked account,
 * resend too early). The message is Russian UI text; controllers show it next to the form field `$field`.
 */
class CustomerAuthException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'email')
    {
        parent::__construct($message);
    }
}
