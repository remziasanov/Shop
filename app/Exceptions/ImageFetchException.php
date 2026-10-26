<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A photo could not be downloaded or resolved (IM-02, IM-11). The message is translated and safe to show.
 */
final class ImageFetchException extends RuntimeException {}
