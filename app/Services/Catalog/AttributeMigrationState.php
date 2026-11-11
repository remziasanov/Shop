<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * State of the migration of characteristics from JSON (FL-04): whether it is finished, whether it is still needed
 * and the progress «N из M» of the running one. The progress lives in the file cache without a TTL, so a restarted
 * job continues from the last finished batch; it is dropped when the migration finishes.
 */
final class AttributeMigrationState
{
    public const CACHE_KEY = 'attributes:migration';

    private const SCAN_CHUNK = 500;

    public function __construct(private readonly CatalogSettings $settings) {}

    public function isMigrated(): bool
    {
        return $this->settings->attributes_migrated_at !== null;
    }

    /**
     * Some product still keeps characteristics in JSON and the migration has not finished (banner, shop:doctor).
     */
    public function isPending(): bool
    {
        return ! $this->isMigrated() && $this->jsonProductCount(true) > 0;
    }

    /**
     * Products whose `attributes` column holds characteristics (an empty list does not count). `$existsOnly` stops
     * at the first one. Decoded in PHP: comparing a JSON column with `[]` is not portable between SQLite and MySQL.
     */
    public function jsonProductCount(bool $existsOnly = false): int
    {
        $count = 0;

        DB::table('products')->whereNotNull('attributes')->select(['id', 'attributes'])
            ->chunkById(self::SCAN_CHUNK, function ($rows) use (&$count, $existsOnly): bool {
                foreach ($rows as $row) {
                    if (self::hasSpecs($row->attributes)) {
                        $count++;
                    }
                }

                return ! ($existsOnly && $count > 0);
            });

        return $count;
    }

    /**
     * Ids (of the given ones) of products that only have v1 characteristics in JSON: no rows in the tables yet.
     * While the migration is not finished, writing such a product through the tables would rebuild its JSON from
     * the new rows only and the migrator would skip it afterwards (FL-04): the v1 characteristics would be lost.
     *
     * @param  array<int>  $productIds
     * @return list<int>
     */
    public function jsonOnlyProductIds(array $productIds): array
    {
        if ($this->isMigrated() || $productIds === []) {
            return [];
        }

        $found = [];

        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), self::SCAN_CHUNK) as $chunk) {
            $withRows = array_flip(DB::table('product_attribute_values')->whereIn('product_id', $chunk)->distinct()->pluck('product_id')->map(fn ($id): int => (int) $id)->all());

            foreach (DB::table('products')->whereIn('id', $chunk)->whereNotNull('attributes')->get(['id', 'attributes']) as $row) {
                if (! isset($withRows[(int) $row->id]) && self::hasSpecs($row->attributes)) {
                    $found[] = (int) $row->id;
                }
            }
        }

        return $found;
    }

    /**
     * The raw JSON of `products.attributes` holds at least one characteristic.
     */
    public static function hasSpecs(mixed $json): bool
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;

        return is_array($decoded) && $decoded !== [];
    }

    /**
     * @return array{total: int, done: int, last_id: int, skipped: int, created: int, started_at: int, run_id: string}|null
     */
    public function current(): ?array
    {
        /** @var array{total: int, done: int, last_id: int, skipped: int, created: int, started_at: int, run_id: string}|null $state */
        $state = Cache::get(self::CACHE_KEY);

        return is_array($state) ? $state : null;
    }

    public function begin(int $total, int $created): void
    {
        Cache::forever(self::CACHE_KEY, ['total' => $total, 'done' => 0, 'last_id' => 0, 'skipped' => 0, 'created' => $created, 'started_at' => now()->getTimestamp(), 'run_id' => now()->format('Ymd-His')]);
    }

    public function advance(int $lastId, int $done, int $skipped): void
    {
        $state = $this->current();

        if ($state === null) {
            return;
        }

        $state['last_id'] = $lastId;
        $state['done'] += $done;
        $state['skipped'] += $skipped;
        Cache::forever(self::CACHE_KEY, $state);
    }

    /**
     * Marks the migration as finished: sets the flag, drops the progress.
     */
    public function finish(): void
    {
        $this->settings->attributes_migrated_at = now()->toIso8601String();
        $this->settings->save();
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Forgets the progress (the next run starts from the first product).
     */
    public function reset(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
