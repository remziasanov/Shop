<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Storage;

/**
 * Backup of the original v1 `products.attributes` JSON taken by the migration from JSON (FL-04, D-112): the migration
 * may drop values that do not fit the inferred type, and the JSON is the only copy of them.
 *
 * Format: JSONL files `attribute-migration/backup-<run-id>.jsonl` on the private (local) disk, one line
 * `{"product_id": 1, "attributes": [...]}` per product. A product is written once: the first original wins across
 * batches, re-runs and files (a re-run sees rebuilt JSON, not the original). Used by `shop:attributes-restore-json`.
 */
class AttributeJsonBackup
{
    public const DIRECTORY = 'attribute-migration';

    /** @var array<int, true>|null product ids already present in any backup file */
    private ?array $known = null;

    /**
     * Appends the originals of products that are not in the backup yet.
     *
     * @param  array<int, mixed>  $originals  product id => decoded original `attributes`
     * @return int number of lines written
     */
    public function append(string $runId, array $originals): int
    {
        $known = $this->known();
        $lines = '';
        $written = 0;

        foreach ($originals as $productId => $attributes) {
            if (isset($known[$productId])) {
                continue;
            }

            $lines .= json_encode(['product_id' => $productId, 'attributes' => $attributes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $this->known[$productId] = true;
            $written++;
        }

        if ($lines !== '') {
            $disk = Storage::disk('local');
            $disk->makeDirectory(self::DIRECTORY);
            file_put_contents($disk->path(self::DIRECTORY.'/backup-'.$runId.'.jsonl'), $lines, FILE_APPEND | LOCK_EX);
        }

        return $written;
    }

    /**
     * The original JSON of the products (all of them when `$productId` is null); the first line of a product wins.
     *
     * @return array<int, mixed> product id => decoded original `attributes`
     */
    public function originals(?int $productId = null): array
    {
        $result = [];

        foreach ($this->files() as $path) {
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);

                if (! is_array($row) || ! isset($row['product_id']) || ! array_key_exists('attributes', $row)) {
                    continue;
                }

                $id = (int) $row['product_id'];

                if (($productId === null || $productId === $id) && ! array_key_exists($id, $result)) {
                    $result[$id] = $row['attributes'];
                }
            }

            fclose($handle);
        }

        return $result;
    }

    /**
     * Absolute path of the backup directory (for messages).
     */
    public function directory(): string
    {
        return Storage::disk('local')->path(self::DIRECTORY);
    }

    /**
     * @return array<int, true>
     */
    private function known(): array
    {
        if ($this->known === null) {
            $this->known = [];

            foreach (array_keys($this->originals()) as $id) {
                $this->known[$id] = true;
            }
        }

        return $this->known;
    }

    /**
     * @return list<string> absolute paths, oldest run first
     */
    private function files(): array
    {
        $disk = Storage::disk('local');
        $files = array_values(array_filter($disk->files(self::DIRECTORY), fn (string $file): bool => str_ends_with($file, '.jsonl')));
        sort($files);

        return array_map(fn (string $file): string => $disk->path($file), $files);
    }
}
