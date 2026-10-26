<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ImageProcessingException;
use App\Models\Category;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

/**
 * Turns a category image uploaded through the admin form (private temporary directory) into the
 * processed public image and stores its path (AD-31, TZ section 2). The temporary file is always
 * removed; the previous image file is deleted by the Category observer when `image_path` changes.
 */
final class CategoryImageImporter
{
    public const TEMP_DISK = 'local';

    public const TEMP_DIRECTORY = 'tmp/category-images';

    public function __construct(
        private readonly ImageProcessor $images,
        private readonly FilesystemFactory $filesystems,
    ) {}

    /**
     * @param  string|null  $temporaryPath  path on the temporary disk, or null to leave the image alone
     * @param  bool  $remove  drop the current image (ignored when a new one is given)
     *
     * @throws ImageProcessingException
     */
    public function apply(Category $category, ?string $temporaryPath, bool $remove = false): void
    {
        if ($temporaryPath === null || $temporaryPath === '') {
            if ($remove && $category->image_path !== null) {
                $category->update(['image_path' => null]);
            }

            return;
        }

        // Uploaded state is client-controllable: validate before anything can be read or deleted, so a
        // foreign path is never touched.
        if (! self::isTemporaryPath($temporaryPath)) {
            throw ImageProcessingException::notFound();
        }

        $disk = $this->filesystems->disk(self::TEMP_DISK);

        try {
            $path = $this->images->processCategoryImage($disk->path($temporaryPath), $category);

            try {
                $category->update(['image_path' => $path]);
            } catch (Throwable $e) {
                $this->filesystems->disk(ImageProcessor::DISK)->delete($path);

                throw $e;
            }
        } finally {
            $disk->delete($temporaryPath);
        }
    }

    /**
     * A plain relative path strictly inside the temporary directory (no absolute path, `..` or backslashes).
     */
    public static function isTemporaryPath(string $path): bool
    {
        return str_starts_with($path, self::TEMP_DIRECTORY.'/')
            && strlen($path) > strlen(self::TEMP_DIRECTORY) + 1
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_contains($path, '//')
            && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1;
    }
}
