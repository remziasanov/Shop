<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A product photo could not be processed (AD-50, AD-53). The message is localized and safe to show.
 */
final class ImageProcessingException extends RuntimeException
{
    private const STORAGE_FAILED = 1;

    public static function notFound(): self
    {
        return new self(__('catalog.errors.image_not_found'));
    }

    public static function tooBig(int $maxMegabytes): self
    {
        return new self(__('catalog.errors.image_too_big', ['max' => $maxMegabytes]));
    }

    public static function tooManyPixels(): self
    {
        return new self(__('catalog.errors.image_too_many_pixels'));
    }

    public static function unsupportedType(): self
    {
        return new self(__('catalog.errors.image_unsupported_type'));
    }

    public static function corrupt(?Throwable $previous = null): self
    {
        return new self(__('catalog.errors.image_corrupt'), 0, $previous);
    }

    public static function storageFailed(?Throwable $previous = null): self
    {
        return new self(__('catalog.errors.image_storage_failed'), self::STORAGE_FAILED, $previous);
    }

    /** The image itself is fine; writing it failed (disk full, quota), so a retry may succeed. */
    public function isStorageFailure(): bool
    {
        return $this->getCode() === self::STORAGE_FAILED;
    }

    public static function limitReached(int $max): self
    {
        return new self(__('catalog.errors.image_limit_reached', ['max' => $max]));
    }
}
