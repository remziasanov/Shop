<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Catalog\AttributeJsonBackup;
use App\Services\Catalog\CatalogVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recovery after the migration from JSON (FL-04, D-112): puts the original v1 `products.attributes` JSON back from the
 * backup the migration wrote and deletes the product's rows in the tables, so a new `shop:attributes-migrate` migrates
 * the product again. All products without `--product`.
 */
class AttributesRestoreJsonCommand extends Command
{
    private const CHUNK = 500;

    protected $signature = 'shop:attributes-restore-json {--product= : Restore only this product id}';

    protected $description = 'Restore the original JSON characteristics of products from the migration backup (FL-04)';

    public function handle(AttributeJsonBackup $backup): int
    {
        $option = $this->option('product');
        $productId = $option !== null && $option !== '' ? (int) $option : null;

        if ($option !== null && $option !== '' && ($productId === null || $productId < 1 || ! ctype_digit((string) $option))) {
            $this->error(__('attributes.migration.restore.bad_product'));

            return self::INVALID;
        }

        $originals = $backup->originals($productId);

        if ($originals === []) {
            $this->warn(__('attributes.migration.restore.nothing', ['path' => $backup->directory()]));

            return $productId === null ? self::SUCCESS : self::FAILURE;
        }

        $restored = 0;

        foreach (array_chunk($originals, self::CHUNK, true) as $chunk) {
            $restored += DB::transaction(function () use ($chunk): int {
                $existing = DB::table('products')->whereIn('id', array_keys($chunk))->pluck('id')->map(fn ($id): int => (int) $id)->all();

                foreach ($existing as $id) {
                    DB::table('product_attribute_values')->where('product_id', $id)->delete();
                    DB::table('products')->where('id', $id)->update([
                        'attributes' => $chunk[$id] === null ? null : json_encode($chunk[$id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }

                return count($existing);
            });
        }

        CatalogVersion::bump();

        $this->info(__('attributes.migration.restore.done', ['count' => $restored]));
        $this->line(__('attributes.migration.restore.hint'));

        return self::SUCCESS;
    }
}
