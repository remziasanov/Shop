<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A single import row (or category / photo) cannot be applied. The message is translated and user-facing;
 * the importer records it as an issue of that row and carries on.
 */
final class ImportRowException extends RuntimeException {}
