<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The time budget of the current import step is used up: the photo was not fetched and must be retried later
 * (not a failure of the photo itself, so nothing is reported or remembered).
 */
final class ImageFetchDeadlineException extends RuntimeException {}
