<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;

/**
 * The `failed_jobs` store without bound values (NF-15, D-206).
 *
 * The queue records `(string) $exception` of the ORIGINAL exception, and a QueryException message contains the bound
 * values (names, phones, e-mails). With APP_DEBUG off a QueryException is replaced by its sanitized form before it is
 * written, the same rule as for the log; the trace stays.
 */
final class SanitizingFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    public function log($connection, $queue, $payload, $exception)
    {
        if ($exception instanceof QueryException && ! config('app.debug')) {
            $exception = SanitizedQueryException::from($exception);
        }

        return parent::log($connection, $queue, $payload, $exception);
    }
}
