<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Models\Product;
use App\Services\Media\ImageProcessor;
use App\Services\Media\ProductPhotoImporter;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;

/**
 * Shared photo upload field and result reporting (AD-50…53).
 */
final class PhotoUpload
{
    /**
     * Largest accepted file: min(10 MB, upload_max_filesize, post_max_size), in bytes.
     */
    public static function effectiveMaxBytes(): int
    {
        $limits = [ImageProcessor::MAX_BYTES];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = ini_parse_quantity((string) ini_get($setting));

            // 0 means "no limit" for post_max_size.
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    public static function effectiveMaxLabel(): string
    {
        $bytes = self::effectiveMaxBytes();
        $mb = $bytes / (1024 * 1024);

        return rtrim(rtrim(number_format($mb, 1, ',', ''), '0'), ',').' МБ';
    }

    public static function make(string $name = 'new_photos', ?int $maxFiles = null): FileUpload
    {
        return FileUpload::make($name)
            ->label(__('admin_products.fields.new_photos'))
            ->multiple()
            ->reorderable()
            ->maxFiles($maxFiles ?? ImageProcessor::MAX_PER_PRODUCT)
            ->maxSize(intdiv(self::effectiveMaxBytes(), 1024))
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->disk(ProductPhotoImporter::TEMP_DISK)
            ->directory(ProductPhotoImporter::TEMP_DIRECTORY)
            ->visibility('private')
            ->storeFileNamesIn('file_names')
            ->helperText(__('admin_products.photos.helper', [
                'max' => ImageProcessor::MAX_PER_PRODUCT,
                'size' => self::effectiveMaxLabel(),
            ]));
    }

    /**
     * Process uploaded temporary files and report the result: one notification for the saved photos
     * and one per failed file.
     *
     * @param  array<int|string, string>|string|null  $paths
     * @param  array<string, string>|null  $names
     */
    public static function process(Product $product, array|string|null $paths, ?array $names = null): void
    {
        $paths = array_values(array_filter((array) $paths, 'is_string'));

        if ($paths === []) {
            return;
        }

        $result = app(ProductPhotoImporter::class)->import($product, $paths, $names ?? []);

        if ($result['saved'] > 0) {
            Notification::make()
                ->success()
                ->title(__('admin_products.photos.saved', ['count' => $result['saved']]))
                ->send();
        }

        foreach ($result['errors'] as $name => $message) {
            Notification::make()
                ->danger()
                ->title(__('admin_products.photos.failed', ['name' => $name, 'message' => $message]))
                ->persistent()
                ->send();
        }
    }
}
