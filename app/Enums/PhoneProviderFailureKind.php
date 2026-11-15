<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The complete list of provider failures (PV-05): network error, timeout, a 5xx answer, an authorization error.
 * Nothing else (zero balance, a refused number, limits, a wrong code) is a failure, so nothing else may skip a verification.
 */
enum PhoneProviderFailureKind: string
{
    case Network = 'network';
    case Timeout = 'timeout';
    case Server = 'server';
    case Auth = 'auth';
}
