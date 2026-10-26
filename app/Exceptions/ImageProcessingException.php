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
        return new self(__('catalog.errors.image_storage_failed'), 0, $previous);
    }

    public static function limitReached(int $max): self
    {
        return new self(__('catalog.errors.image_limit_reached', ['max' => $max]));
    }
}
