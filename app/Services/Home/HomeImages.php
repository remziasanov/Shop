<?php

declare(strict_types=1);

namespace App\Services\Home;

use App\Exceptions\ImageProcessingException;
use App\Services\Media\ImageProcessor;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

/**
 * Banner images of the home page builder (5.2, NF-03, TZ section 2).
 *
 * The admin form uploads a file into a private temporary directory; on save {@see apply()} turns it
 * into the public 1600 / 800 px variants and stores the result in the block data as
 * `{large, small, width, height}`. The upload itself is never kept. Images that are replaced, removed or
 * belong to a deleted block are deleted by {@see deleteObsolete()}.
 *
 * Block data comes from the browser, so an existing image reference is only accepted when the same file
 * is already stored in the previous (trusted) settings; nothing else can ever be deleted.
 */
final class HomeImages
{
    public const TEMP_DISK = 'local';

    public const TEMP_DIRECTORY = 'tmp/home-images';

    /** @var list<string> Image fields of a banner / slide; each has `{field}_upload` and `{field}_remove` helpers. */
    private const FIELDS = ['image', 'image_mobile'];

    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly FilesystemFactory $filesystems,
    ) {}

    /**
     * Resolve uploads / removals of the submitted blocks. Returns the blocks without helper fields.
     *
     * @param  list<array<string, mixed>>  $submitted
     * @param  list<array<string, mixed>>  $previous  blocks currently stored in the settings
     * @return list<array<string, mixed>>
     *
     * @throws ImageProcessingException
     */
    public function apply(array $submitted, array $previous): array
    {
        $known = [];
        foreach ($this->images($previous) as $image) {
            $known[$image['large']] = $image;
        }

        $created = [];
        $temporary = [];

        try {
            foreach ($submitted as $index => $block) {
                $type = $block['type'] ?? null;
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];

                if ($type === 'banner') {
                    $data = $this->resolve($data, $known, $created, $temporary);
                } elseif ($type === 'slider') {
                    $slides = [];
                    foreach (is_array($data['slides'] ?? null) ? $data['slides'] : [] as $slide) {
                        $slides[] = $this->resolve(is_array($slide) ? $slide : [], $known, $created, $temporary);
                    }
                    $data['slides'] = $slides;
                }

                $submitted[$index]['data'] = $data;
            }
        } catch (ImageProcessingException $e) {
            $this->publicDisk()->delete($created);

            throw $e;
        } catch (Throwable $e) {
            $this->publicDisk()->delete($created);
            report($e);

            throw ImageProcessingException::corrupt($e);
        } finally {
            $this->filesystems->disk(self::TEMP_DISK)->delete($temporary);
        }

        return $submitted;
    }

    /**
     * Delete the files of images that were in the previous blocks but are not in the current ones.
     *
     * @param  list<array<string, mixed>>  $previous
     * @param  list<array<string, mixed>>  $current
     */
    public function deleteObsolete(array $previous, array $current): void
    {
        $keep = $this->paths($current);
        $delete = array_values(array_diff($this->paths($previous), $keep));

        if ($delete !== []) {
            $this->publicDisk()->delete($delete);
        }
    }

    /**
     * Every image stored in the blocks.
     *
     * @param  array<int|string, mixed>  $blocks
     * @return list<array{large: string, small: string, width: int, height: int}>
     */
    public function images(array $blocks): array
    {
        $images = [];
        $collect = function (array $data) use (&$images): void {
            foreach (self::FIELDS as $field) {
                $image = self::normalize($data[$field] ?? null);

                if ($image !== null) {
                    $images[] = $image;
                }
            }
        };

        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_array($block['data'] ?? null)) {
                continue;
            }

            if (($block['type'] ?? null) === 'banner') {
                $collect($block['data']);
            } elseif (($block['type'] ?? null) === 'slider') {
                foreach (is_array($block['data']['slides'] ?? null) ? $block['data']['slides'] : [] as $slide) {
                    if (is_array($slide)) {
                        $collect($slide);
                    }
                }
            }
        }

        return $images;
    }

    /**
     * Valid image reference `{large, small, width, height}` or null.
     *
     * @return array{large: string, small: string, width: int, height: int}|null
     */
    public static function normalize(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['large'] ?? null) || ! is_string($value['small'] ?? null)) {
            return null;
        }

        $width = (int) ($value['width'] ?? 0);
        $height = (int) ($value['height'] ?? 0);

        if ($value['large'] === '' || $width < 1 || $height < 1) {
            return null;
        }

        return ['large' => $value['large'], 'small' => $value['small'], 'width' => $width, 'height' => $height];
    }

    /**
     * A plain relative path strictly inside the temporary directory.
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

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array{large: string, small: string, width: int, height: int}>  $known
     * @param  list<string>  $created
     * @param  list<string>  $temporary
     * @return array<string, mixed>
     */
    private function resolve(array $data, array $known, array &$created, array &$temporary): array
    {
        foreach (self::FIELDS as $field) {
            $upload = $data[$field.'_upload'] ?? null;
            $remove = (bool) ($data[$field.'_remove'] ?? false);
            unset($data[$field.'_upload'], $data[$field.'_remove']);

            $path = is_array($upload) ? (reset($upload) ?: null) : $upload;
            $current = self::normalize($data[$field] ?? null);
            $current = $current !== null ? ($known[$current['large']] ?? null) : null;

            if (is_string($path) && $path !== '') {
                // Whatever the client sent, only files in our temporary directory are ever read or deleted.
                if (! self::isTemporaryPath($path)) {
                    throw ImageProcessingException::notFound();
                }

                $temporary[] = $path;
                $image = $this->processor->processBannerImage($this->filesystems->disk(self::TEMP_DISK)->path($path));
                array_push($created, $image['large'], $image['small']);
                $data[$field] = $image;
            } else {
                $data[$field] = $remove ? null : $current;
            }
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    private function paths(array $blocks): array
    {
        $paths = [];

        foreach ($this->images($blocks) as $image) {
            array_push($paths, $image['large'], $image['small']);
        }

        return array_values(array_unique($paths));
    }

    private function publicDisk(): Filesystem
    {
        return $this->filesystems->disk(ImageProcessor::DISK);
    }
}
