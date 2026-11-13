<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Enums\OptionDisplayType;
use App\Exceptions\ImageProcessingException;
use App\Exceptions\OptionException;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Services\Catalog\CatalogVersion;
use App\Services\Media\ImageProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The option dictionary (VR-01, VR-26): values with colour and swatch image, order, merge, deletion that is refused
 * while cards use the value, and the lookups the import uses (VR-16: options and values are created on demand).
 *
 * Writes bump the catalog version (26.3), because option names and values show up in storefront filters.
 */
final class OptionService
{
    /** Directory of the temporary swatch uploads on the private `local` disk. */
    public const TEMP_DIRECTORY = 'tmp/option-swatches';

    /** Names that make a created option a colour swatch on import. */
    private const COLOR_NAMES = ['цвет', 'color', 'colour'];

    /** @var array<int, array<string, OptionValue>> option type id => normalized text => value */
    private array $valueMaps = [];

    /** @var array<string, OptionType>|null normalized name => option type */
    private ?array $typeMap = null;

    public function __construct(private readonly ImageProcessor $images) {}

    // ------------------------------------------------------------------
    // Usage
    // ------------------------------------------------------------------

    /**
     * Cards that have a variant with the value (variants in the trash count: the FK still holds the value).
     */
    public function valueUsage(OptionValue $value): int
    {
        return (int) DB::table('product_variant_values as pvv')
            ->join('product_variants as pv', 'pv.id', '=', 'pvv.variant_id')
            ->where('pvv.option_value_id', $value->id)
            ->distinct()
            ->count('pv.product_id');
    }

    /**
     * Cards that use the option as an axis or have a variant value of it.
     */
    public function typeUsage(OptionType $type): int
    {
        $axes = DB::table('product_options')->where('option_type_id', $type->id)->pluck('product_id');
        $variants = DB::table('product_variant_values as pvv')
            ->join('product_variants as pv', 'pv.id', '=', 'pvv.variant_id')
            ->where('pvv.option_type_id', $type->id)
            ->distinct()
            ->pluck('pv.product_id');

        return $axes->merge($variants)->unique()->count();
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  name*, slug, display_type, is_filterable, sort_order, is_active
     */
    public function createType(array $data): OptionType
    {
        $type = new OptionType;
        $type->fill([
            'name' => trim((string) ($data['name'] ?? '')),
            'slug' => trim((string) ($data['slug'] ?? '')),
            'display_type' => $this->displayType($data['display_type'] ?? null),
            'is_filterable' => (bool) ($data['is_filterable'] ?? false),
            'sort_order' => isset($data['sort_order']) && $data['sort_order'] !== '' ? (int) $data['sort_order'] : ((int) OptionType::query()->max('sort_order')) + 10,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $type->save();

        $this->changed();

        return $type;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateType(OptionType $type, array $data): OptionType
    {
        $fill = [];

        foreach (['name', 'slug'] as $key) {
            if (array_key_exists($key, $data)) {
                $fill[$key] = trim((string) $data[$key]);
            }
        }

        if (array_key_exists('display_type', $data)) {
            $fill['display_type'] = $this->displayType($data['display_type']);
        }

        foreach (['is_filterable', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $fill[$flag] = (bool) $data[$flag];
            }
        }

        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== '' && $data['sort_order'] !== null) {
            $fill['sort_order'] = (int) $data['sort_order'];
        }

        if (($fill['slug'] ?? null) === '') {
            unset($fill['slug']);
        }

        $type->fill($fill)->save();
        $this->changed();

        return $type;
    }

    /**
     * Deletes an option that no card uses (axes and variants alike); otherwise refuses with the number of cards.
     *
     * @throws OptionException
     */
    public function deleteType(OptionType $type): void
    {
        $count = $this->typeUsage($type);

        if ($count > 0) {
            throw OptionException::typeInUse($type->name, $count);
        }

        $paths = OptionValue::query()->where('option_type_id', $type->id)->whereNotNull('image_path')->pluck('image_path')->all();

        DB::transaction(function () use ($type): void {
            // Photos tied to a value fall back to «common» through the FK; the values go with the type.
            $type->delete();
        });

        $this->deleteSwatches($paths);
        $this->changed();
    }

    // ------------------------------------------------------------------
    // Values
    // ------------------------------------------------------------------

    /**
     * Adds a value (VR-01): text, optional colour `#rrggbb` and swatch image (a path in the temporary directory).
     * The text is unique inside the option; the slug is made unique ("-2", "-3").
     *
     * @param  array<string, mixed>  $data  value*, slug, hex, swatch (temporary path), sort_order
     *
     * @throws OptionException
     */
    public function createValue(OptionType $type, array $data): OptionValue
    {
        $text = $this->cleanValue((string) ($data['value'] ?? ''), $type);
        $this->assertTextFree($type, $text, null);

        $value = DB::transaction(function () use ($type, $text, $data): OptionValue {
            $value = new OptionValue;
            $value->fill([
                'option_type_id' => $type->id,
                'value' => $text,
                'slug' => trim((string) ($data['slug'] ?? '')),
                'hex' => $this->cleanHex($data['hex'] ?? null),
                'sort_order' => isset($data['sort_order']) && $data['sort_order'] !== '' ? (int) $data['sort_order'] : ((int) OptionValue::query()->where('option_type_id', $type->id)->max('sort_order')) + 10,
            ]);
            $value->save();

            return $value;
        });

        $this->applySwatch($value, $data['swatch'] ?? null, false);
        unset($this->valueMaps[$type->id]);
        $this->changed();

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data  value, slug, hex, swatch (temporary path), remove_swatch, sort_order
     *
     * @throws OptionException
     */
    public function updateValue(OptionValue $value, array $data): OptionValue
    {
        $type = OptionType::query()->findOrFail($value->option_type_id);
        $fill = [];

        if (array_key_exists('value', $data)) {
            $text = $this->cleanValue((string) $data['value'], $type);
            $this->assertTextFree($type, $text, $value->id);
            $fill['value'] = $text;
        }

        if (array_key_exists('slug', $data) && trim((string) $data['slug']) !== '') {
            $fill['slug'] = trim((string) $data['slug']);
        }

        if (array_key_exists('hex', $data)) {
            $fill['hex'] = $this->cleanHex($data['hex']);
        }

        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== '' && $data['sort_order'] !== null) {
            $fill['sort_order'] = (int) $data['sort_order'];
        }

        $value->fill($fill)->save();
        $this->applySwatch($value, $data['swatch'] ?? null, (bool) ($data['remove_swatch'] ?? false));
        unset($this->valueMaps[$value->option_type_id]);
        $this->changed();

        return $value;
    }

    /**
     * Drag-and-drop order: the given ids first, others keep their place after them.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorderValues(OptionType $type, array $orderedIds): void
    {
        DB::transaction(function () use ($type, $orderedIds): void {
            $position = 0;

            foreach ($orderedIds as $id) {
                OptionValue::query()->where('option_type_id', $type->id)->where('id', (int) $id)->update(['sort_order' => ++$position * 10]);
            }

            $rest = OptionValue::query()->where('option_type_id', $type->id)->whereNotIn('id', array_map('intval', $orderedIds))->orderBy('sort_order')->orderBy('id')->pluck('id');

            foreach ($rest as $id) {
                OptionValue::query()->where('id', $id)->update(['sort_order' => ++$position * 10]);
            }
        });

        $this->changed();
    }

    /**
     * Deletes a value that no variant uses (VR-26); otherwise refuses with the number of cards.
     *
     * @throws OptionException
     */
    public function deleteValue(OptionValue $value): void
    {
        $count = $this->valueUsage($value);

        if ($count > 0) {
            throw OptionException::valueInUse($value->value, $count);
        }

        $path = $value->image_path;
        $value->delete();
        unset($this->valueMaps[$value->option_type_id]);

        $this->deleteSwatches($path === null ? [] : [$path]);
        $this->changed();
    }

    /**
     * «Объединить значения» (VR-26): the variants and photos of `$from` move to `$to`, then `$from` is deleted. Refused
     * when a card has variants for both values with the same other values (the combination would exist twice).
     *
     * @return int number of cards that had `$from`
     *
     * @throws OptionException
     */
    public function mergeValues(OptionValue $from, OptionValue $to): int
    {
        if ($from->id === $to->id) {
            throw OptionException::mergeSame();
        }

        if ($from->option_type_id !== $to->option_type_id) {
            throw OptionException::mergeOtherOption();
        }

        $variantIds = DB::table('product_variant_values')->where('option_value_id', $from->id)->pluck('variant_id')->map(fn (mixed $id): int => (int) $id)->all();
        $cards = [];

        DB::transaction(function () use ($from, $to, $variantIds, &$cards): void {
            $variants = [];

            foreach (array_chunk($variantIds, VariantService::CHUNK_SIZE) as $chunk) {
                foreach (DB::table('product_variants')->whereIn('id', $chunk)->get(['id', 'product_id', 'sku']) as $row) {
                    $variants[(int) $row->id] = $row;
                }
            }

            $cardIds = array_values(array_unique(array_map(fn (object $row): int => (int) $row->product_id, $variants)));
            sort($cardIds);

            // Lock order of 19.5: card rows first, ascending.
            if ($cardIds !== []) {
                DB::table('products')->whereIn('id', $cardIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }

            $conflicts = [];
            $hashes = [];

            foreach (array_chunk($variantIds, VariantService::CHUNK_SIZE) as $chunk) {
                $own = DB::table('product_variant_values')->whereIn('variant_id', $chunk)->get(['variant_id', 'option_value_id']);
                $byVariant = [];

                foreach ($own as $row) {
                    $id = (int) $row->option_value_id;
                    $byVariant[(int) $row->variant_id][] = $id === $from->id ? $to->id : $id;
                }

                foreach ($byVariant as $variantId => $ids) {
                    $hash = VariantService::comboHash($ids);
                    $hashes[$variantId] = $hash;
                    $clash = DB::table('product_variants')
                        ->where('product_id', $variants[$variantId]->product_id)
                        ->where('combo_hash', $hash)
                        ->where('id', '!=', $variantId)
                        ->exists();

                    if ($clash) {
                        $conflicts[] = (string) $variants[$variantId]->sku;
                    }
                }
            }

            if ($conflicts !== []) {
                throw OptionException::mergeConflict($conflicts);
            }

            foreach (array_chunk($variantIds, VariantService::CHUNK_SIZE) as $chunk) {
                DB::table('product_variant_values')->whereIn('variant_id', $chunk)->where('option_value_id', $from->id)->update(['option_value_id' => $to->id]);

                foreach ($chunk as $variantId) {
                    DB::table('product_variants')->where('id', $variantId)->update(['combo_hash' => $hashes[$variantId]]);
                }
            }

            DB::table('product_images')->where('option_value_id', $from->id)->update(['option_value_id' => $to->id]);

            $cards = $cardIds;
            $path = $from->image_path;
            $from->delete();

            if ($path !== null) {
                DB::afterCommit(fn () => $this->deleteSwatches([$path]));
            }
        });

        unset($this->valueMaps[$from->option_type_id]);
        $this->changed();

        return count($cards);
    }

    // ------------------------------------------------------------------
    // Import lookups (VR-16)
    // ------------------------------------------------------------------

    /**
     * Finds an option by its name (case, spaces and ё insensitive) or creates it when `$create` is true; null when it is
     * missing and may not be created. A created «Цвет» is a colour swatch, every other option a drop-down list.
     */
    public function findOrCreateType(string $name, bool $create): ?OptionType
    {
        $name = $this->collapse($name);

        if ($name === '') {
            return null;
        }

        $this->typeMap ??= $this->loadTypes();
        $key = $this->normalize($name);

        if (isset($this->typeMap[$key])) {
            return $this->typeMap[$key];
        }

        if (! $create) {
            return null;
        }

        $type = $this->createType([
            'name' => mb_substr($name, 0, 100),
            'display_type' => in_array($key, self::COLOR_NAMES, true) ? OptionDisplayType::Color : OptionDisplayType::Select,
            'is_filterable' => false,
        ]);

        return $this->typeMap[$key] = $type;
    }

    /**
     * Finds a value of the option by its text (case, spaces and ё insensitive) or creates it when `$create` is true.
     */
    public function findOrCreateValue(OptionType $type, string $text, bool $create): ?OptionValue
    {
        $text = $this->collapse($text);

        if ($text === '') {
            return null;
        }

        $map = &$this->valueMap($type->id);
        $key = $this->normalize($text);

        if (isset($map[$key])) {
            return $map[$key];
        }

        if (! $create) {
            return null;
        }

        $value = new OptionValue;
        $value->fill([
            'option_type_id' => $type->id,
            'value' => mb_substr($text, 0, 150),
            'slug' => '',
            'sort_order' => ((int) OptionValue::query()->where('option_type_id', $type->id)->max('sort_order')) + 10,
        ]);
        $value->save();

        return $map[$key] = $value;
    }

    /**
     * Forgets what the instance has read (a rolled back import chunk must not leave stale entries).
     */
    public function forgetCache(): void
    {
        $this->valueMaps = [];
        $this->typeMap = null;
    }

    // ------------------------------------------------------------------
    // Swatch images
    // ------------------------------------------------------------------

    /**
     * A plain relative path strictly inside the temporary directory (no absolute path, `..` or backslashes).
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
     * Number of cards using the value, for lists without a query per row.
     *
     * @param  list<int>  $valueIds
     * @return array<int, int> value id => cards
     */
    public function valueUsages(array $valueIds): array
    {
        if ($valueIds === []) {
            return [];
        }

        $rows = DB::table('product_variant_values as pvv')
            ->join('product_variants as pv', 'pv.id', '=', 'pvv.variant_id')
            ->whereIn('pvv.option_value_id', $valueIds)
            ->groupBy('pvv.option_value_id')
            ->selectRaw('pvv.option_value_id as value_id, count(distinct pv.product_id) as cards')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row->value_id] = (int) $row->cards;
        }

        return $result;
    }

    /**
     * @throws OptionException an unusable image (NF-11) or a foreign path
     */
    private function applySwatch(OptionValue $value, mixed $upload, bool $remove): void
    {
        $temporary = is_array($upload) ? (reset($upload) ?: null) : $upload;
        $temporary = is_string($temporary) && $temporary !== '' ? $temporary : null;

        if ($temporary === null) {
            if ($remove && $value->image_path !== null) {
                $old = $value->image_path;
                $value->forceFill(['image_path' => null])->save();
                $this->deleteSwatches([$old]);
            }

            return;
        }

        if (! self::isTemporaryPath($temporary)) {
            throw OptionException::swatchNotFound();
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($temporary)) {
            throw OptionException::swatchNotFound();
        }

        try {
            $path = $this->images->processSwatchImage($disk->path($temporary));
        } catch (ImageProcessingException $e) {
            if (! $e->isStorageFailure()) {
                $disk->delete($temporary);
            }

            throw new OptionException($e->getMessage());
        }

        $old = $value->image_path;
        $value->forceFill(['image_path' => $path])->save();
        $disk->delete($temporary);

        if ($old !== null) {
            $this->deleteSwatches([$old]);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteSwatches(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk(ImageProcessor::DISK)->delete($paths);
        }
    }

    // ------------------------------------------------------------------

    /**
     * @throws OptionException
     */
    private function cleanValue(string $text, OptionType $type): string
    {
        $text = $this->collapse($text);

        if ($text === '') {
            throw OptionException::valueRequired($type->name);
        }

        return mb_substr($text, 0, 150);
    }

    /**
     * @throws OptionException
     */
    private function assertTextFree(OptionType $type, string $text, ?int $ignoreId): void
    {
        $existing = $this->valueMap($type->id)[$this->normalize($text)] ?? null;

        if ($existing !== null && $existing->id !== $ignoreId) {
            throw OptionException::valueExists($text);
        }
    }

    /**
     * @throws OptionException
     */
    private function cleanHex(mixed $hex): ?string
    {
        if ($hex === null || trim((string) $hex) === '') {
            return null;
        }

        $hex = strtolower(trim((string) $hex));

        if (preg_match('/^#[0-9a-f]{6}$/', $hex) !== 1) {
            throw OptionException::colorInvalid();
        }

        return $hex;
    }

    private function displayType(mixed $raw): OptionDisplayType
    {
        return $raw instanceof OptionDisplayType ? $raw : (OptionDisplayType::tryFrom((string) $raw) ?? OptionDisplayType::Select);
    }

    /**
     * @return array<string, OptionType>
     */
    private function loadTypes(): array
    {
        $map = [];

        foreach (OptionType::query()->orderBy('id')->get() as $type) {
            $map[$this->normalize($type->name)] ??= $type;
        }

        return $map;
    }

    /**
     * @return array<string, OptionValue>
     */
    private function &valueMap(int $typeId): array
    {
        if (! isset($this->valueMaps[$typeId])) {
            $map = [];

            foreach (OptionValue::query()->where('option_type_id', $typeId)->orderBy('id')->get() as $value) {
                $map[$this->normalize($value->value)] ??= $value;
            }

            $this->valueMaps[$typeId] = $map;
        }

        return $this->valueMaps[$typeId];
    }

    private function collapse(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function normalize(string $text): string
    {
        return str_replace('ё', 'е', mb_strtolower($this->collapse($text), 'UTF-8'));
    }

    private function changed(): void
    {
        CatalogVersion::bump();
    }
}
