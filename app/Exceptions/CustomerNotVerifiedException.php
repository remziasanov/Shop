<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The password was right but the e-mail address is not confirmed yet (CA-03). A new confirmation code has been sent
 * (or was sent a moment ago); the controller continues on the confirmation page.
 */
class CustomerNotVerifiedException extends CustomerAuthException
{
    public function __construct(public readonly string $email)
    {
        parent::__construct(__('account.errors.not_verified'), 'email');
    }
}
