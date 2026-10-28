<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A staff-management rule was violated (system role integrity, escalation, last administrator).
 * The message is a translated, user-facing text.
 */
class StaffAccessException extends RuntimeException {}
