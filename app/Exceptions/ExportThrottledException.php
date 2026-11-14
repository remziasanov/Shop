<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A customer hit a limit of the export feature (CX-09): too many requests, too many unfinished exports or too many
 * downloads. The controller answers 429 with `Retry-After`.
 */
final class ExportThrottledException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }
}
