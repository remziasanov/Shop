<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Models\Product;
use App\Services\Content\HtmlSanitizer;
use App\Services\Stock\StockService;
use Livewire\Attributes\Locked;

/**
 * Translates between the form state and the model: `specs` <-> `attributes`, sanitized description,
 * and the photo upload keys that are not columns.
 */
trait MapsProductFormData
{
    /**
     * Stock figure the form was filled with (D-052). `stock_qty` is not dehydrated, so a save never writes the
     * possibly stale value from the browser; only a change made by the user goes through StockService::setStock().
     */
    #[Locked]
    public ?int $loadedStockQty = null;

    protected function rememberLoadedStock(Product $product): void
    {
        $this->loadedStockQty = $product->stock_qty;
    }

    /**
     * Applies the stock field when the user changed it relative to the loaded value; otherwise re-syncs the
     * field with the real figure (an order may have changed it meanwhile).
     */
    protected function applyStockField(Product $product): void
    {
        $raw = $this->form->getRawState()['stock_qty'] ?? null;
        $submitted = $raw === null || $raw === '' ? null : (int) $raw;

        if ($submitted !== $this->loadedStockQty) {
            app(StockService::class)->setStock($product, $submitted);
        } else {
            $product->refresh();
            $submitted = $product->stock_qty;
        }

        $this->loadedStockQty = $submitted;
        $this->data['stock_qty'] = $submitted;
    }

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
