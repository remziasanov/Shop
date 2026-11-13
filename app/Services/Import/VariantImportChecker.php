<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportField;
use App\Enums\ImportMode;
use Illuminate\Support\Facades\DB;

/**
 * The cross-row checks of variant rows during the analysis (VR-17), the part {@see RowValidator} cannot do because it
 * knows neither the file above nor the database:
 *
 *  - the card of a variant exists in the database or appears above in the file (and has no errors there);
 *  - an article belongs to the other kind of entity (a card where a variant is expected, and the other way round) → error;
 *  - the axes of a card are set by its first variant and must match afterwards (the axes of a card that already works
 *    with variants come from the database); every axis needs a value;
 *  - the same combination of values is not repeated in the file for one card;
 *  - a variant article of another card is refused.
 *
 * One instance serves one analysis pass, rows must be fed in file order: {@see prepare()} for a batch, then
 * {@see check()} and {@see record()} per row.
 */
final class VariantImportChecker
{
    /**
     * Cards known so far by lower-cased article: from the file (rows above) or from the database.
     *
     * @var array<string, array{row: int, ok: bool, id: int|null, trashed: bool, axes: list<string>|null}>
     */
    private array $cards = [];

    /** @var array<string, int> lower-cased variant article => first row in the file */
    private array $fileVariants = [];

    /** @var array<string, array<string, int>> card key => combination key => first row */
    private array $combinations = [];

    /** @var array<string, int> lower-cased product article => id, for the articles of the current batch */
    private array $dbProducts = [];

    /** @var array<string, array{product_id: int}> lower-cased variant article => data, for the articles of the current batch */
    private array $dbVariants = [];

    /**
     * Loads what the batch needs from the database: products and variants with the articles of the rows and of their
     * parents, and the axes of cards that already work with variants.
     *
     * @param  list<MappedRow>  $rows
     */
    public function prepare(array $rows): void
    {
        $skus = [];
        $parents = [];

        foreach ($rows as $row) {
            $sku = $this->text($row->get('sku'));
            $parent = $this->text($row->get('parent_sku'));

            if ($sku !== null) {
                $skus[$this->key($sku)] = $sku;
            }

            if ($parent !== null) {
                $parents[$this->key($parent)] = $parent;
            }
        }

        $this->dbProducts = [];
        $this->dbVariants = [];

        $lookup = array_values($skus + $parents);

        foreach (array_chunk($lookup, 500) as $chunk) {
            foreach (DB::table('product_variants')->whereIn('sku', $chunk)->get(['sku', 'product_id']) as $variant) {
                $this->dbVariants[$this->key((string) $variant->sku)] = ['product_id' => (int) $variant->product_id];
            }
        }

        $ids = [];
        $cardsByKey = [];

        foreach (array_chunk($lookup, 500) as $chunk) {
            foreach (DB::table('products')->whereIn('sku', $chunk)->get(['id', 'sku', 'has_variants', 'deleted_at']) as $product) {
                $key = $this->key((string) $product->sku);
                $this->dbProducts[$key] = (int) $product->id;
                $cardsByKey[$key] = $product;

                if ((bool) $product->has_variants) {
                    $ids[(int) $product->id] = $key;
                }
            }
        }

        $axes = [];

        foreach (array_chunk(array_keys($ids), 500) as $chunk) {
            $rows = DB::table('product_options as po')
                ->join('option_types as ot', 'ot.id', '=', 'po.option_type_id')
                ->whereIn('po.product_id', $chunk)
                ->orderBy('po.product_id')->orderBy('po.sort_order')
                ->get(['po.product_id', 'ot.name']);

            foreach ($rows as $axis) {
                $axes[(int) $axis->product_id][] = (string) $axis->name;
            }
        }

        // Cards of the file keep what the file said about them (the card row restores a trashed card, D-038).
        foreach ($cardsByKey as $key => $product) {
            if (isset($this->cards[$key])) {
                $this->cards[$key]['id'] = (int) $product->id;
                $this->cards[$key]['axes'] ??= isset($axes[(int) $product->id]) ? $axes[(int) $product->id] : null;

                continue;
            }

            $this->cards[$key] = [
                'row' => 0,
                'ok' => true,
                'id' => (int) $product->id,
                'trashed' => $product->deleted_at !== null,
                'axes' => (bool) $product->has_variants && isset($axes[(int) $product->id]) ? $axes[(int) $product->id] : null,
            ];
        }
    }

    /**
     * Whether a variant with the article exists (a soft-deleted one counts); only valid for the articles of the batch.
     */
    public function variantExists(string $sku): bool
    {
        return isset($this->dbVariants[$this->key($sku)]);
    }

    /**
     * Issues of one row.
     *
     * @return array{errors: list<RowIssue>, warnings: list<RowIssue>}
     */
    public function check(RowValidationResult $result, MappedRow $row, ImportMode $mode): array
    {
        $sku = $this->text($row->get('sku'));
        $errors = [];

        if ($sku === null) {
            return ['errors' => [], 'warnings' => []];
        }

        $skuKey = $this->key($sku);
        $field = ImportField::Sku;
        $error = function (string $message, ImportField|string $target, ?string $value = null) use (&$errors): void {
            $errors[] = $target instanceof ImportField
                ? new RowIssue($target->value, $target->label(), $message, $value)
                : new RowIssue($target, ColumnMapper::fieldLabel($target), $message, $value);
        };

        if (! $result->isVariant()) {
            // A card or a plain product: its article must not be a variant's.
            if (isset($this->dbVariants[$skuKey])) {
                $error(__('admin_variants.import.sku_is_variant', ['sku' => $sku]), $field, $sku);
            }

            return ['errors' => $errors, 'warnings' => []];
        }

        if (isset($this->dbProducts[$skuKey])) {
            $error(__('admin_variants.import.sku_is_card', ['sku' => $sku]), $field, $sku);
        }

        $parent = (string) $this->text($row->get('parent_sku'));
        $parentKey = $this->key($parent);
        $card = $this->cards[$parentKey] ?? null;

        if ($mode === ImportMode::PricesStock) {
            // Prices and stock only touch existing variants; a missing card or variant is skipped, not an error.
            return ['errors' => $errors, 'warnings' => []];
        }

        if ($card === null) {
            if (isset($this->dbVariants[$parentKey]) || isset($this->fileVariants[$parentKey])) {
                $error(__('admin_variants.import.parent_is_variant', ['sku' => $parent]), ImportField::ParentSku, $parent);
            } else {
                $error(__('admin_variants.import.card_missing', ['sku' => $parent]), ImportField::ParentSku, $parent);
            }

            return ['errors' => $errors, 'warnings' => []];
        }

        if ($card['trashed']) {
            $error(__('admin_variants.import.card_trashed', ['sku' => $parent]), ImportField::ParentSku, $parent);
        } elseif (! $card['ok']) {
            $error(__('admin_variants.import.card_invalid', ['sku' => $parent, 'row' => $card['row']]), ImportField::ParentSku, $parent);
        }

        $own = $this->dbVariants[$skuKey] ?? null;

        if ($own !== null && $card['id'] !== null && $own['product_id'] !== $card['id']) {
            $error(__('admin_variants.import.variant_other_card', ['sku' => $sku]), $field, $sku);
        }

        foreach ($this->axisErrors($result, $card, $parentKey) as [$message, $target, $value]) {
            $error($message, $target, $value);
        }

        return ['errors' => $errors, 'warnings' => []];
    }

    /**
     * Remembers the row for the rows below it: a card row becomes a known card, a valid variant row sets the axes of its
     * card and occupies its combination.
     */
    public function record(RowValidationResult $result, MappedRow $row, int $rowNumber): void
    {
        $sku = $this->text($row->get('sku'));

        if ($sku === null) {
            return;
        }

        $key = $this->key($sku);

        if (! $result->isVariant()) {
            $known = $this->cards[$key] ?? null;

            $this->cards[$key] = [
                'row' => $rowNumber,
                'ok' => $result->isValid(),
                'id' => $known['id'] ?? ($this->dbProducts[$key] ?? null),
                // The row of the card restores a trashed card.
                'trashed' => false,
                'axes' => $known['axes'] ?? null,
            ];

            return;
        }

        $this->fileVariants[$key] ??= $rowNumber;

        if (! $result->isValid()) {
            return;
        }

        $parentKey = $this->key((string) $this->text($row->get('parent_sku')));

        if (isset($this->cards[$parentKey]) && $this->cards[$parentKey]['axes'] === null) {
            $this->cards[$parentKey]['axes'] = array_map(fn (array $cell): string => $cell['name'], $result->options);
        }

        $this->combinations[$parentKey][$this->combinationKey($result->options)] ??= $rowNumber;
    }

    /**
     * @param  array{row: int, ok: bool, id: int|null, trashed: bool, axes: list<string>|null}  $card
     * @return list<array{0: string, 1: ImportField|string, 2: string|null}>
     */
    private function axisErrors(RowValidationResult $result, array $card, string $parentKey): array
    {
        $errors = [];
        $cells = [];

        foreach ($result->options as $cell) {
            $cells[$this->key($cell['name'])] = $cell;
        }

        if ($cells === []) {
            return [[__('admin_variants.import.options_missing'), ImportField::ParentSku, null]];
        }

        if (count($cells) > 3) {
            return [[__('admin_variants.import.axes_count'), ImportField::ParentSku, null]];
        }

        if ($card['axes'] === null) {
            // The first variant sets the axes: only the repeated combination can be wrong.
            $axes = array_values($cells);
        } else {
            $axes = [];
            $expected = [];

            foreach ($card['axes'] as $name) {
                $expected[$this->key($name)] = $name;
            }

            foreach ($expected as $key => $name) {
                if (! isset($cells[$key])) {
                    $errors[] = [__('admin_variants.import.option_blank', ['name' => $name]), ImportField::optionKey($name), null];
                }
            }

            foreach ($cells as $key => $cell) {
                if (! isset($expected[$key])) {
                    $errors[] = [__('admin_variants.import.option_not_axis', ['name' => $cell['name'], 'axes' => implode(', ', $card['axes'])]), ImportField::optionKey($cell['name']), $cell['value']];
                }
            }

            $axes = array_values($cells);
        }

        if ($errors === []) {
            $first = $this->combinations[$parentKey][$this->combinationKey($axes)] ?? null;

            if ($first !== null) {
                $errors[] = [__('admin_variants.import.combo_duplicate', ['row' => $first]), ImportField::ParentSku, null];
            }
        }

        return $errors;
    }

    /**
     * @param  list<array{name: string, value: string}>  $options
     */
    private function combinationKey(array $options): string
    {
        $pairs = array_map(fn (array $cell): string => $this->key($cell['name']).'='.$this->key($cell['value']), $options);
        sort($pairs);

        return implode('|', $pairs);
    }

    private function key(string $text): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8'));
    }

    private function text(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }
}
