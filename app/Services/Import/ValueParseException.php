<?php

declare(strict_types=1);

namespace App\Services\Import;

use InvalidArgumentException;

/**
 * A cell value cannot be parsed. The message is a translated, user-facing text.
 */
final class ValueParseException extends InvalidArgumentException {}
