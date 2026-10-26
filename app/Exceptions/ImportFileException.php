<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The uploaded import file cannot be read (unsupported format, missing sheet, no data).
 * The message is a translated, user-facing text.
 */
class ImportFileException extends RuntimeException {}
