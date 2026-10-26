<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ProductImage;
use App\Services\Media\ImageProcessor;

final class ProductImageObserver
{
    public function __construct(private readonly ImageProcessor $images) {}

    /**
     * Deleting a photo deletes all its files (AD-52).
     */
    public function deleted(ProductImage $image): void
    {
        $this->images->deleteFiles($image);
    }
}
