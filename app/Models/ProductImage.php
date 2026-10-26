<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ProductImageObserver;
use Database\Factories\ProductImageFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One photo with three variants (D-020) on the "public" disk.
 *
 * @property int $id
 * @property int $product_id
 * @property string $path_large
 * @property string $path_medium
 * @property string $path_small
 * @property string|null $alt
 * @property int $sort_order
 * @property int $width Width of the large variant.
 * @property int $height Height of the large variant.
 * @property string|null $source_hash
 * @property-read Product $product
 */
#[ObservedBy(ProductImageObserver::class)]
class ProductImage extends Model
{
    /** @use HasFactory<ProductImageFactory> */
    use HasFactory;

    /** Long-side limits of the variants in pixels. */
    public const SIZE_LARGE = 1600;

    public const SIZE_MEDIUM = 800;

    public const SIZE_SMALL = 400;

    protected $fillable = [
        'product_id',
        'path_large',
        'path_medium',
        'path_small',
        'alt',
        'sort_order',
        'width',
        'height',
        'source_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function urlLarge(): string
    {
        return self::urlFor($this->path_large);
    }

    public function urlMedium(): string
    {
        return self::urlFor($this->path_medium);
    }

    public function urlSmall(): string
    {
        return self::urlFor($this->path_small);
    }

    /**
     * Value for the `srcset` attribute built from the real variant widths (variants are never upscaled,
     * so a small source yields identical variants, which are listed once).
     */
    public function srcset(): string
    {
        $longSide = max($this->width, $this->height);
        $entries = [];

        foreach ([
            self::SIZE_SMALL => $this->path_small,
            self::SIZE_MEDIUM => $this->path_medium,
            self::SIZE_LARGE => $this->path_large,
        ] as $limit => $path) {
            $scale = $longSide > 0 ? min(1, $limit / $longSide) : 1;
            $width = max(1, (int) round($this->width * $scale));
            $entries[$width] ??= self::urlFor($path);
        }

        $parts = [];
        foreach ($entries as $width => $url) {
            $parts[] = $url.' '.$width.'w';
        }

        return implode(', ', $parts);
    }

    private static function urlFor(string $path): string
    {
        return Storage::disk('public')->url($path);
    }
}
