<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A wholesale application rule was violated (CG-20, CG-22). The message is user-facing (Russian).
 */
class WholesaleApplicationException extends RuntimeException {}
