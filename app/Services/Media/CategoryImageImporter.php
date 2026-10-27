<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ImageProcessingException;
use App\Models\Category;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

/**
 * Turns a category image uploaded through the admin form (private temporary directory) into the
 * processed public image and stores its path (AD-31, TZ section 2). The temporary file is removed after
 * success or an unusable image, and kept after any other failure (`shop:cleanup` removes it later). The
 * previous image file is deleted by the Category observer when `image_path` changes.
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
                $category->update(['image_path' => null, 'image_source_hash' => null]);
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
        } catch (ImageProcessingException $e) {
            // The file itself is unusable: nothing to retry with (unless only writing the result failed).
            if (! $e->isStorageFailure()) {
                $disk->delete($temporaryPath);
            }

            throw $e;
        }

        try {
            // A manual upload is no longer the import's image: forget its source hash.
            $category->update(['image_path' => $path, 'image_source_hash' => null]);
        } catch (Throwable $e) {
            // Keep the temporary file: the form still references it, so a retry can succeed.
            $this->filesystems->disk(ImageProcessor::DISK)->delete($path);

            throw $e;
        }

        $disk->delete($temporaryPath);
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
