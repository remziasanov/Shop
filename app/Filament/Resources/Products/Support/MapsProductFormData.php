<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Models\Product;
use App\Services\Content\HtmlSanitizer;

/**
 * Translates between the form state and the model: `specs` <-> `attributes`, sanitized description,
 * and the photo upload keys that are not columns.
 */
trait MapsProductFormData
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillSpecs(Product $product, array $data): array
    {
        $data['specs'] = $product->specs();

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareProductData(array $data): array
    {
        $specs = [];

        foreach ((array) ($data['specs'] ?? []) as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name !== '') {
                $specs[] = ['name' => $name, 'value' => trim((string) ($row['value'] ?? ''))];
            }
        }

        $data['attributes'] = $specs;
        $data['description'] = app(HtmlSanitizer::class)->sanitize($data['description'] ?? null) ?: null;
        $data['slug'] = trim((string) ($data['slug'] ?? ''));
        $data['unit'] = trim((string) ($data['unit'] ?? '')) ?: 'шт';

        unset($data['specs'], $data['new_photos'], $data['file_names']);

        return $data;
    }
}
