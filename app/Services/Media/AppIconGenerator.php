<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\ImageProcessingException;
use App\Settings\AppearanceSettings;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * App icons of the installed shop (MB-21): from one square image the generator writes four PNG files to
 * `storage/app/public/app-icons/`: 192 and 512 (purpose «any»), 512 «maskable» (the icon in the safe 80 % zone on the
 * background color, so a launcher can crop it freely) and 180 (apple-touch-icon, no transparency).
 *
 * The file names carry a content hash of the source image and the background color, so a new icon or color gives new
 * URLs (the browser cache of the old icon is dropped) and the previous set is deleted. PNG, JPG and WebP are accepted;
 * SVG is not (NF-11, getimagesize() does not recognize it).
 */
final class AppIconGenerator
{
    public const DISK = 'public';

    public const DIRECTORY = 'app-icons';

    /** Minimum side of the uploaded square icon, px. */
    public const MIN_SIZE = 512;

    /** Side of the intermediate copy the icons are built from, px. */
    private const BASE_SIZE = 512;

    /** Pixel limit of the source (4096 x 4096): the icons are cut from one 512 px copy, but the source is decoded once at full size. */
    public const MAX_PIXELS = 16_777_216;

    /** Share of the maskable canvas kept free around the icon on each side. */
    public const MASKABLE_PADDING = 0.10;

    /** icon kind => [file prefix, side in px]. */
    private const KINDS = [
        'icon-192' => ['icon-192', 192],
        'icon-512' => ['icon-512', 512],
        'maskable' => ['icon-512-maskable', 512],
        'apple' => ['apple-touch-icon', 180],
    ];

    /** @var array<int, true> */
    private const ALLOWED_TYPES = [
        IMAGETYPE_PNG => true,
        IMAGETYPE_JPEG => true,
        IMAGETYPE_WEBP => true,
    ];

    private ?ImageManager $manager = null;

    public function __construct(private readonly FilesystemFactory $storage) {}

    /**
     * Checks the source image: PNG/JPG/WebP, square and at least 512×512.
     *
     * @throws ImageProcessingException
     */
    public function validate(string $contents): void
    {
        // getimagesizefromstring() reads the header only and returns false for SVG and other formats.
        $info = @getimagesizefromstring($contents);

        if ($info === false || ! isset(self::ALLOWED_TYPES[$info[2]])) {
            throw ImageProcessingException::appIconUnsupportedType();
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw ImageProcessingException::appIconTooLarge(4096);
        }

        if ($info[0] !== $info[1]) {
            throw ImageProcessingException::appIconNotSquare();
        }

        if ($info[0] < self::MIN_SIZE) {
            throw ImageProcessingException::appIconTooSmall(self::MIN_SIZE);
        }
    }

    /**
     * Brings the generated set in line with the settings: no icon removes the files, a new icon or background color
     * writes a new set (and deletes the old one). Saves the hash into the settings when it changed.
     *
     * @throws ImageProcessingException
     */
    public function sync(AppearanceSettings $settings): void
    {
        $icon = $settings->pwa_icon;
        $disk = $this->disk();

        if ($icon === null || $icon === '' || ! $disk->exists($icon)) {
            $this->purge();
            $this->remember($settings, null);

            return;
        }

        $contents = (string) $disk->get($icon);
        $hash = self::hash($contents, $settings->pwa_background_color);

        if ($hash === $settings->pwa_icons_hash && $this->complete($hash)) {
            return;
        }

        $this->generate($contents, $settings->pwa_background_color);
        $this->remember($settings, $hash);
    }

    /**
     * Writes the four icons and deletes every other file of the directory.
     *
     * @return string the content hash used in the file names
     *
     * @throws ImageProcessingException
     */
    public function generate(string $contents, string $background): string
    {
        $this->validate($contents);

        $background = self::normalizeColor($background);
        $hash = self::hash($contents, $background);
        $disk = $this->disk();

        try {
            // One decode of the source, scaled to the largest icon; every variant is cut from this copy.
            $base = $this->manager()->read($contents)->cover(self::BASE_SIZE, self::BASE_SIZE)->toPng()->toString();

            foreach (self::KINDS as $kind => [, $size]) {
                $disk->put($this->path($kind, $hash), $this->render($base, $kind, $size, $background));
            }
        } catch (ImageProcessingException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ImageProcessingException::corrupt($e);
        }

        $keep = array_map(fn (string $kind): string => $this->path($kind, $hash), array_keys(self::KINDS));

        foreach ($disk->files(self::DIRECTORY) as $file) {
            if (! in_array($file, $keep, true)) {
                $disk->delete($file);
            }
        }

        return $hash;
    }

    /** Deletes every generated icon. */
    public function purge(): void
    {
        $disk = $this->disk();

        foreach ($disk->files(self::DIRECTORY) as $file) {
            $disk->delete($file);
        }
    }

    /**
     * Public URLs of the set `$hash`, by kind: icon-192, icon-512, maskable, apple.
     *
     * @return array<string, string>
     */
    public function urls(string $hash): array
    {
        $urls = [];

        foreach (array_keys(self::KINDS) as $kind) {
            $urls[$kind] = $this->disk()->url($this->path($kind, $hash));
        }

        return $urls;
    }

    /** Whether all four files of the set exist. */
    public function complete(string $hash): bool
    {
        foreach (array_keys(self::KINDS) as $kind) {
            if (! $this->disk()->exists($this->path($kind, $hash))) {
                return false;
            }
        }

        return true;
    }

    /** Content hash of the set: the source bytes plus the normalized background color. */
    public static function hash(string $contents, string $background): string
    {
        return substr(hash('sha256', $contents.'|'.self::normalizeColor($background)), 0, 12);
    }

    /** `#abc` / `#AABBCC` -> `#aabbcc`; anything else falls back to white. */
    public static function normalizeColor(string $color): string
    {
        $color = strtolower(trim($color));

        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $m) === 1) {
            return '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : '#ffffff';
    }

    private function path(string $kind, string $hash): string
    {
        return self::DIRECTORY.'/'.self::KINDS[$kind][0].'-'.$hash.'.png';
    }

    private function render(string $contents, string $kind, int $size, string $background): string
    {
        $manager = $this->manager();

        if ($kind === 'maskable') {
            $inner = (int) round($size * (1 - 2 * self::MASKABLE_PADDING));

            return $manager->create($size, $size)
                ->fill($background)
                ->place($manager->read($contents)->cover($inner, $inner), 'center')
                ->toPng()
                ->toString();
        }

        if ($kind === 'apple') {
            // iOS paints transparency black: the background color goes behind the icon.
            return $manager->create($size, $size)
                ->fill($background)
                ->place($manager->read($contents)->cover($size, $size), 'center')
                ->toPng()
                ->toString();
        }

        return $manager->read($contents)->cover($size, $size)->toPng()->toString();
    }

    private function remember(AppearanceSettings $settings, ?string $hash): void
    {
        if ($settings->pwa_icons_hash !== $hash) {
            $settings->pwa_icons_hash = $hash;
            $settings->save();
        }
    }

    private function manager(): ImageManager
    {
        return $this->manager ??= new ImageManager(new Driver, autoOrientation: true, decodeAnimation: false);
    }

    private function disk(): Filesystem
    {
        return $this->storage->disk(self::DISK);
    }
}
