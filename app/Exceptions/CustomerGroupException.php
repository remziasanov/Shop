<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A customer group rule was violated (a system group cannot be deleted, CG-01). The message is user-facing.
 */
class CustomerGroupException extends RuntimeException {}
