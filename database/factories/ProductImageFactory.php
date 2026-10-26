<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Creates rows only; the files do not exist on disk (use ImageProcessor for real files).
 *
 * @extends Factory<ProductImage>
 */
class ProductImageFactory extends Factory
{
    protected $model = ProductImage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = Str::uuid()->toString();

        return [
            'product_id' => Product::factory(),
            'path_large' => "products/0/{$uuid}-1600.webp",
            'path_medium' => "products/0/{$uuid}-800.webp",
            'path_small' => "products/0/{$uuid}-400.webp",
            'alt' => null,
            'sort_order' => 0,
            'width' => 1600,
            'height' => 1200,
            'source_hash' => null,
        ];
    }
}
