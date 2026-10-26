<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ImageProcessingException;
use App\Models\Product;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Throwable;

/**
 * Turns files uploaded through the admin form (stored in a private temporary directory) into product
 * photos. A failing file is reported by name and does not stop the others (AD-53).
 */
final class ProductPhotoImporter
{
    public const TEMP_DISK = 'local';

    public const TEMP_DIRECTORY = 'tmp/product-photos';

    public function __construct(
        private readonly ImageProcessor $images,
        private readonly FilesystemFactory $filesystems,
    ) {}

    /**
     * @param  list<string>  $paths  paths on the temporary disk, in the desired order
     * @param  array<string, string>  $originalNames  path => client file name, used in error messages
     * @return array{saved: int, errors: array<string, string>} errors: file name => message
     */
    public function import(Product $product, array $paths, array $originalNames = []): array
    {
        $disk = $this->filesystems->disk(self::TEMP_DISK);
        $saved = 0;
        $errors = [];

        foreach ($paths as $path) {
            $name = $originalNames[$path] ?? basename($path);

            // Uploaded state is client-controllable: only our own temporary directory is accepted.
            if (! $this->isTemporaryPath($path)) {
                $errors[$name] = __('catalog.errors.image_not_found');

                continue;
            }

            try {
                $this->images->attachToProduct($product, $disk->path($path), null, null, true);
                $saved++;
            } catch (ImageProcessingException $e) {
                $errors[$name] = $e->getMessage();
            } catch (Throwable $e) {
                report($e);
                $errors[$name] = __('catalog.errors.image_corrupt');
            } finally {
                $disk->delete($path);
            }
        }

        return ['saved' => $saved, 'errors' => $errors];
    }

    private function isTemporaryPath(string $path): bool
    {
        return str_starts_with($path, self::TEMP_DIRECTORY.'/')
            && strlen($path) > strlen(self::TEMP_DIRECTORY) + 1
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && ! str_contains($path, '//')
            && preg_match('/[\x00-\x1f\x7f]/', $path) !== 1;
    }
}
