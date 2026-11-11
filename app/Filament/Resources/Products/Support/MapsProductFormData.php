<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Exceptions\AttributeException;
use App\Models\Product;
use App\Services\Catalog\ProductAttributeEditor;
use App\Services\Content\HtmlSanitizer;
use App\Services\Stock\StockService;
use Closure;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

/**
 * Translates between the form state and the model: `specs` <-> `attributes` (legacy), `typed_specs` <-> tables, sanitized description,
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

    /**
     * Rows of the typed characteristics editor of the submitted form; null when the editor was not submitted.
     *
     * @var list<array<string, mixed>>|null
     */
    protected ?array $typedSpecs = null;

    /**
     * Runs the save of the product and writes the typed characteristics in the same transaction (FL-03). A domain
     * error (a word in a number field) is shown as a notification and nothing is saved.
     *
     * @param  Closure(): Model  $save
     */
    protected function saveWithAttributes(Closure $save): Model
    {
        try {
            return DB::transaction(function () use ($save): Model {
                $record = $save();

                if ($this->typedSpecs !== null && $record instanceof Product) {
                    app(ProductAttributeEditor::class)->save($record, $this->typedSpecs);
                }

                return $record;
            });
        } catch (AttributeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            throw new Halt;
        }
    }

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
        $editor = app(ProductAttributeEditor::class);

        if ($editor->isLegacy($product)) {
            $data['specs'] = $product->specs();
        } else {
            $data['typed_specs'] = $editor->load($product);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareProductData(array $data): array
    {
        // Legacy JSON editor: only products that were not migrated send `specs`; otherwise the JSON cache is
        // written by AttributeService and the column must not be touched here.
        if (array_key_exists('specs', $data)) {
            $specs = [];

            foreach ((array) $data['specs'] as $row) {
                $name = trim((string) ($row['name'] ?? ''));

                if ($name !== '') {
                    $specs[] = ['name' => $name, 'value' => trim((string) ($row['value'] ?? ''))];
                }
            }

            $data['attributes'] = $specs;
        }

        $this->typedSpecs = array_key_exists('typed_specs', $data) ? array_values((array) $data['typed_specs']) : null;
        $data['description'] = app(HtmlSanitizer::class)->sanitize($data['description'] ?? null) ?: null;
        $data['slug'] = trim((string) ($data['slug'] ?? ''));
        $data['unit'] = trim((string) ($data['unit'] ?? '')) ?: 'шт';

        unset($data['specs'], $data['typed_specs'], $data['new_photos'], $data['file_names']);

        return $data;
    }
}
