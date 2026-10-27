<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ImageProcessingException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Product photo pipeline (AD-50…53, D-020).
 *
 * Reads JPEG/PNG/WEBP/GIF (first frame of animations; SVG and everything else is rejected), applies
 * the EXIF orientation, never upscales and writes three variants (long side 1600 / 800 / 400 px) to
 * the "public" disk as `products/{product_id}/{uuid}-{size}.webp` (JPEG when GD has no WebP).
 * The source file is never stored; {@see attachToProduct()} can delete it after success.
 *
 * Every failure is an {@see ImageProcessingException} with a localized message, so the caller can
 * report it for that single file and carry on with the others (AD-53).
 */
final class ImageProcessor
{
    public const DISK = 'public';

    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Decoded bitmaps need ~5 bytes per pixel in GD; this keeps the peak under ~128 MB. */
    public const MAX_PIXELS = 25_000_000;

    public const MAX_PER_PRODUCT = 15;

    /** Longest side of a category image, px (tiles and banners). */
    public const CATEGORY_SIZE = 800;

    /** Width of the large / small banner variants of the home page, px. */
    public const BANNER_LARGE = 1600;

    public const BANNER_SMALL = 800;

    public const WEBP_QUALITY = 80;

    public const JPEG_QUALITY = 85;

    /** @var array<int, true> Image types accepted from getimagesize(). */
    private const ALLOWED_TYPES = [
        IMAGETYPE_JPEG => true,
        IMAGETYPE_PNG => true,
        IMAGETYPE_GIF => true,
        IMAGETYPE_WEBP => true,
    ];

    private ?ImageManager $manager = null;

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    /**
     * Extension of the written variants: "webp" when GD supports it, otherwise "jpg".
     */
    public function outputExtension(): string
    {
        return function_exists('imagewebp') && (imagetypes() & IMG_WEBP) ? 'webp' : 'jpg';
    }

    /**
     * Create the three variants for a product and return the data for a `product_images` row.
     *
     * @param  string  $sourceAbsolutePath  readable image file (e.g. a Livewire temporary upload)
     * @return array{path_large: string, path_medium: string, path_small: string, width: int, height: int}
     *
     * @throws ImageProcessingException
     */
    public function process(string $sourceAbsolutePath, Product $product): array
    {
        $this->assertReadable($sourceAbsolutePath);

        if (! $product->exists) {
            throw new \LogicException('The product must be saved before its photos are processed.');
        }

        $extension = $this->outputExtension();
        $base = 'products/'.$product->id.'/'.Str::uuid()->toString();
        $disk = $this->disk();
        $written = [];

        try {
            try {
                $image = $this->manager()->read($sourceAbsolutePath);
            } catch (Throwable $e) {
                throw ImageProcessingException::corrupt($e);
            }

            $result = [];
            foreach ([
                'path_large' => ProductImage::SIZE_LARGE,
                'path_medium' => ProductImage::SIZE_MEDIUM,
                'path_small' => ProductImage::SIZE_SMALL,
            ] as $key => $size) {
                $variant = $image->scaleDown($size, $size);
                $path = "{$base}-{$size}.{$extension}";

                if (! $disk->put($path, $this->encode($variant, $extension))) {
                    throw ImageProcessingException::storageFailed();
                }

                $written[] = $path;
                $result[$key] = $path;

                if ($key === 'path_large') {
                    $result['width'] = $variant->width();
                    $result['height'] = $variant->height();
                }
            }

            return $result;
        } catch (ImageProcessingException $e) {
            $disk->delete($written);

            throw $e;
        } catch (Throwable $e) {
            $disk->delete($written);

            throw ImageProcessingException::corrupt($e);
        }
    }

    /**
     * Create the single variant of a category image (long side 800 px, never upscaled) and return its
     * path on the "public" disk: `categories/{category_id}/{uuid}-800.webp` (JPEG without WebP in GD).
     * The source file is not stored and not touched.
     *
     * @throws ImageProcessingException
     */
    public function processCategoryImage(string $sourceAbsolutePath, Category $category): string
    {
        $this->assertReadable($sourceAbsolutePath);

        if (! $category->exists) {
            throw new \LogicException('The category must be saved before its image is processed.');
        }

        $extension = $this->outputExtension();
        $size = self::CATEGORY_SIZE;
        $path = 'categories/'.$category->id.'/'.Str::uuid()->toString()."-{$size}.{$extension}";
        $disk = $this->disk();

        try {
            $image = $this->manager()->read($sourceAbsolutePath);
        } catch (Throwable $e) {
            throw ImageProcessingException::corrupt($e);
        }

        try {
            $stored = $disk->put($path, $this->encode($image->scaleDown($size, $size), $extension));
        } catch (Throwable $e) {
            $disk->delete($path);

            throw ImageProcessingException::corrupt($e);
        }

        if (! $stored) {
            throw ImageProcessingException::storageFailed();
        }

        return $path;
    }

    /**
     * Create the two variants of a home page banner (width 1600 / 800 px, never upscaled) in
     * `home/{uuid}-{size}.webp` on the "public" disk (JPEG without WebP in GD). The source is not stored
     * and not touched.
     *
     * @return array{large: string, small: string, width: int, height: int} width / height of the large variant
     *
     * @throws ImageProcessingException
     */
    public function processBannerImage(string $sourceAbsolutePath): array
    {
        $this->assertReadable($sourceAbsolutePath);

        $extension = $this->outputExtension();
        $base = 'home/'.Str::uuid()->toString();
        $disk = $this->disk();
        $written = [];

        try {
            try {
                $image = $this->manager()->read($sourceAbsolutePath);
            } catch (Throwable $e) {
                throw ImageProcessingException::corrupt($e);
            }

            $result = ['large' => '', 'small' => '', 'width' => 0, 'height' => 0];

            foreach (['large' => self::BANNER_LARGE, 'small' => self::BANNER_SMALL] as $key => $size) {
                $variant = $image->scaleDown(width: $size);
                $path = "{$base}-{$size}.{$extension}";

                if (! $disk->put($path, $this->encode($variant, $extension))) {
                    throw ImageProcessingException::storageFailed();
                }

                $written[] = $path;
                $result[$key] = $path;

                if ($key === 'large') {
                    $result['width'] = $variant->width();
                    $result['height'] = $variant->height();
                }
            }

            return $result;
        } catch (ImageProcessingException $e) {
            $disk->delete($written);

            throw $e;
        } catch (Throwable $e) {
            $disk->delete($written);

            throw ImageProcessingException::corrupt($e);
        }
    }

    /**
     * Process a file and create the ProductImage row (sort_order = last + 1, alt defaults to the
     * product name, AD-52).
     *
     * @param  bool  $deleteSource  remove the source file after success (temporary uploads)
     *
     * @throws ImageProcessingException
     */
    public function attachToProduct(Product $product, string $sourceAbsolutePath, ?string $alt = null, ?string $sourceHash = null, bool $deleteSource = false): ProductImage
    {
        $hashed = $sourceHash !== null && $sourceHash !== '';

        // Cheap early exits before the (slow) GD work: an import image (has a source hash) is attached at most once.
        if ($hashed && ($existing = $product->images()->where('source_hash', $sourceHash)->first()) !== null) {
            if ($deleteSource) {
                @unlink($sourceAbsolutePath);
            }

            return $existing;
        }

        if ($product->images()->count() >= self::MAX_PER_PRODUCT) {
            throw ImageProcessingException::limitReached(self::MAX_PER_PRODUCT);
        }

        // Decoding and writing the variants takes seconds: done outside of any transaction / row lock.
        $data = $this->process($sourceAbsolutePath, $product);
        $files = [$data['path_large'], $data['path_medium'], $data['path_small']];

        try {
            // Short transaction: under the product row lock the hash and the limit are checked again (two workers
            // may have processed the same photo meanwhile) and the row is inserted.
            [$image, $duplicate] = DB::transaction(function () use ($product, $data, $alt, $sourceHash, $hashed): array {
                if ($hashed) {
                    Product::withTrashed()->whereKey($product->getKey())->lockForUpdate()->first(['id']);

                    $existing = $product->images()->where('source_hash', $sourceHash)->first();

                    if ($existing !== null) {
                        return [$existing, true];
                    }
                }

                if ($this->imageCount($product) >= self::MAX_PER_PRODUCT) {
                    throw ImageProcessingException::limitReached(self::MAX_PER_PRODUCT);
                }

                $alt = $alt !== null && trim($alt) !== '' ? trim($alt) : $product->name;
                $nextOrder = $product->images()->max('sort_order');

                return [$product->images()->create($data + [
                    'alt' => $alt,
                    'sort_order' => $nextOrder === null ? 0 : ((int) $nextOrder) + 1,
                    'source_hash' => $sourceHash,
                ]), false];
            });
        } catch (Throwable $e) {
            $this->disk()->delete($files);

            throw $e;
        }

        if ($duplicate) {
            // Lost the race: the freshly written files are not needed, the winner's image is returned.
            $this->disk()->delete($files);
        }

        if ($deleteSource) {
            @unlink($sourceAbsolutePath);
        }

        $product->unsetRelation('images');

        return $image;
    }

    private function imageCount(Product $product): int
    {
        return $product->images()->count();
    }

    /**
     * Delete all three files of an image (the row itself is untouched).
     */
    public function deleteFiles(ProductImage $image): void
    {
        $this->disk()->delete([$image->path_large, $image->path_medium, $image->path_small]);
    }

    /**
     * Delete every photo file of a product (permanent deletion, AD-44).
     */
    public function deleteProductDirectory(Product $product): void
    {
        $this->disk()->deleteDirectory('products/'.$product->id);
    }

    private function assertReadable(string $path): void
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw ImageProcessingException::notFound();
        }

        $size = filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            throw ImageProcessingException::tooBig(intdiv(self::MAX_BYTES, 1024 * 1024));
        }

        // getimagesize() only reads the header: cheap, and rejects SVG/text/other formats.
        $info = @getimagesize($path);

        if ($info === false) {
            // Unreadable header: SVG/XML is an unsupported type, anything else is treated as damaged.
            $head = strtolower((string) file_get_contents($path, false, null, 0, 1024));

            throw str_contains($head, '<svg') || str_contains($head, '<?xml')
                ? ImageProcessingException::unsupportedType()
                : ImageProcessingException::corrupt();
        }

        if (! isset(self::ALLOWED_TYPES[$info[2]])) {
            throw ImageProcessingException::unsupportedType();
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw ImageProcessingException::tooManyPixels();
        }
    }

    private function encode(ImageInterface $image, string $extension): string
    {
        $encoded = $extension === 'webp'
            ? $image->toWebp(self::WEBP_QUALITY)
            : $image->toJpeg(self::JPEG_QUALITY);

        return (string) $encoded;
    }

    private function manager(): ImageManager
    {
        // Auto-orientation (EXIF) is on by default; animations are decoded as the first frame only.
        return $this->manager ??= new ImageManager(new Driver, autoOrientation: true, decodeAnimation: false);
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk(self::DISK);
    }
}
