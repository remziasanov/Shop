<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\StockDisplay;
use App\Enums\StorefrontSection;
use App\Jobs\BuildPriceList;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Access\AccessSet;
use App\Services\Access\CatalogAccess;
use App\Services\Catalog\CategoryTree;
use App\Services\Export\XlsxBook;
use App\Services\Stock\Stockable;
use App\Services\Variants\VariantLabel;
use App\Settings\SalesSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The wholesale price list of a customer group (CG-17): an Excel file with every product and variant the group may see
 * - article, barcode, name, category, brand, unit, stock by the group's display rule, the group's price and its tiers.
 *
 * The file lives on the private disk (`price-lists/{group id}.xlsx`) and is rebuilt by {@see BuildPriceList} jobs: the
 * products are read in chunks of 500 by id (GP-03: one price load per chunk) into a temporary line file, and the jobs
 * continue one another when the time budget of a job is spent (every job stays under 40 s, TZ section 2). The last job
 * writes the workbook and swaps it in atomically, so a download never sees a half-written file.
 */
final class PriceListBuilder
{
    public const DISK = 'local';

    public const CHUNK = 500;

    /** Seconds one job may spend reading products before it hands over to the next one. */
    public const STEP_SECONDS = 25;

    /** Cursor value of the job that writes the workbook. */
    public const WRITE = -1;

    private int $chunk = self::CHUNK;

    public function __construct(
        private readonly CatalogAccess $access,
        private readonly ViewerPrices $prices,
        private readonly CategoryTree $tree,
        private readonly VariantLabel $labels,
        private readonly SalesSettings $sales,
    ) {}

    /**
     * A builder that reads `$size` products per chunk (tests exercise the hand-over between jobs with small chunks).
     */
    public function withChunk(int $size): self
    {
        $copy = clone $this;
        $copy->chunk = max(1, $size);

        return $copy;
    }

    /**
     * Active groups that offer the price list (the section is on) and show prices at all.
     *
     * @return Collection<int, CustomerGroup>
     */
    public function eligibleGroups(): Collection
    {
        return CustomerGroup::query()->active()->ordered()->get()->filter(fn (CustomerGroup $group): bool => $this->isEligible($group))->values();
    }

    public function isEligible(CustomerGroup $group): bool
    {
        $access = $this->access->for($group);

        return $group->exists && $group->is_active && $access->allowsSection(StorefrontSection::PriceList) && $access->showsPrices();
    }

    /**
     * One queued build for every eligible group (`shop:build-price-lists`); returns the number of builds queued.
     */
    public function queueAll(): int
    {
        $groups = $this->eligibleGroups();

        foreach ($groups as $group) {
            $this->queue($group);
        }

        return $groups->count();
    }

    /**
     * «Обновить» in the admin and the nightly command: queue one build of the group.
     */
    public function queue(CustomerGroup $group): void
    {
        BuildPriceList::dispatch($group->id, Str::random(12));
    }

    /**
     * Build the whole file at once, without the queue (tests, console); returns the number of rows.
     */
    public function buildNow(CustomerGroup $group): int
    {
        $run = Str::random(12);
        $this->begin($group, $run);
        $cursor = 0;

        while ($cursor !== null) {
            $cursor = $this->step($group, $run, $cursor);
        }

        return $this->finish($group, $run);
    }

    /**
     * Start of a run: stale line files of the group are dropped.
     */
    public function begin(CustomerGroup $group, string $run): void
    {
        $disk = Storage::disk(self::DISK);

        foreach ($disk->files('price-lists/tmp') as $file) {
            if (str_starts_with(basename($file), $group->id.'-') && $disk->lastModified($file) < now()->subHour()->getTimestamp()) {
                $disk->delete($file);
            }
        }

        $disk->put($this->linesPath($group, $run), '');
    }

    /**
     * Read products after the cursor into the line file until the deadline or the end of the catalog.
     *
     * @param  float|null  $deadline  `microtime(true)` limit; null reads to the end
     * @return int|null the cursor (last product id) to continue from, null when every product has been read
     */
    public function step(CustomerGroup $group, string $run, int $cursor, ?float $deadline = null): ?int
    {
        $access = $this->access->for($group);

        if (! $this->isEligible($group) || ! $access->allowsSection(StorefrontSection::Catalog)) {
            return null;
        }

        $disk = Storage::disk(self::DISK);
        $path = $disk->path($this->linesPath($group, $run));

        do {
            $query = Product::query()->active()->where('products.id', '>', $cursor)->orderBy('products.id')->limit($this->chunk);
            $access->applyToProducts($query);
            $products = $query->get();

            if ($products->isEmpty()) {
                return null;
            }

            $lines = '';

            foreach ($this->rows($products, $group, $access) as $row) {
                $lines .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            }

            file_put_contents($path, $lines, FILE_APPEND | LOCK_EX);
            $cursor = (int) $products->pluck('id')->last();

            if ($products->count() < $this->chunk) {
                return null;
            }
        } while ($deadline === null || microtime(true) < $deadline);

        return $cursor;
    }

    /**
     * Write the workbook from the line file and swap it in; returns the number of rows.
     */
    public function finish(CustomerGroup $group, string $run): int
    {
        $disk = Storage::disk(self::DISK);
        $lines = $this->linesPath($group, $run);
        $disk->makeDirectory('price-lists');
        $temporary = 'price-lists/'.$group->id.'.'.$run.'.tmp';
        $rows = 0;

        $book = new XlsxBook($disk->path($temporary));
        $book->sheet(__('group_prices.price_list.sheet'), [1 => 16, 2 => 16, 3 => 50, 4 => 26, 5 => 20, 6 => 10, 7 => 12, 8 => 14, 9 => 40]);
        $book->header(array_map(fn (string $column): string => __('group_prices.price_list.columns.'.$column), ['sku', 'barcode', 'name', 'category', 'brand', 'unit', 'stock', 'price', 'tiers']));

        if ($disk->exists($lines)) {
            $handle = fopen($disk->path($lines), 'rb');

            if ($handle !== false) {
                while (($line = fgets($handle)) !== false) {
                    /** @var list<string|int|null> $row */
                    $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
                    // Prices are kept as ruble strings ("900.00") and become number cells only here (Excel needs numbers).
                    $row[7] = $row[7] === null ? null : (float) $row[7];
                    $book->row($row);
                    $rows++;
                }

                fclose($handle);
            }
        }

        $book->close();
        $disk->move($temporary, $this->path($group));
        $disk->delete($lines);

        return $rows;
    }

    public function path(CustomerGroup $group): string
    {
        return 'price-lists/'.$group->id.'.xlsx';
    }

    public function exists(CustomerGroup $group): bool
    {
        return Storage::disk(self::DISK)->exists($this->path($group));
    }

    public function builtAt(CustomerGroup $group): ?CarbonImmutable
    {
        $disk = Storage::disk(self::DISK);

        return $disk->exists($this->path($group)) ? CarbonImmutable::createFromTimestamp($disk->lastModified($this->path($group))) : null;
    }

    private function linesPath(CustomerGroup $group, string $run): string
    {
        return 'price-lists/tmp/'.$group->id.'-'.$run.'.ndjson';
    }

    /**
     * The rows of a chunk of products: a product without variants is one row, a card with variants one row per active variant.
     *
     * @param  Collection<int, Product>  $products
     * @return list<list<string|int|null>>
     */
    private function rows(Collection $products, CustomerGroup $group, AccessSet $access): array
    {
        $variants = ProductVariant::query()
            ->whereIn('product_id', $products->pluck('id')->all())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');
        $units = [];

        foreach ($products as $product) {
            if ($product->has_variants) {
                foreach ($variants->get($product->id, []) as $variant) {
                    $units[] = $variant;
                }
            } else {
                $units[] = $product;
            }
        }

        $book = $this->prices->preload($units, $group);
        $labels = $this->labels->forMany($variants->flatten());
        $display = $access->stockDisplay->resolve($this->sales->stock_display);
        $rows = [];

        foreach ($products as $product) {
            $node = $this->tree->node($product->category_id);
            $category = $node !== null ? $node->name : '';

            if (! $product->has_variants) {
                $rows[] = $this->row($product, $product, $product->name, $category, $group, $book, $display);

                continue;
            }

            foreach ($variants->get($product->id, []) as $variant) {
                $variant->setRelation('product', $product);
                $label = $labels[$variant->id] ?? '';
                $rows[] = $this->row($variant, $product, $label !== '' ? $product->name.' ('.$label.')' : $product->name, $category, $group, $book, $display);
            }
        }

        return $rows;
    }

    /**
     * @return list<string|int|null>
     */
    private function row(Product|ProductVariant $unit, Product $product, string $name, string $category, CustomerGroup $group, ?PriceBook $book, StockDisplay $display): array
    {
        $price = null;
        $tiers = '';

        if (! $product->price_on_request) {
            $viewer = $this->prices->for($unit, $group, $book);
            $price = Money::toRubles($viewer->unit);
            $tiers = implode('; ', array_map(
                fn (array $tier): string => __('group_prices.price_list.tier', ['qty' => $tier['min'], 'price' => Money::format($tier['price'])]),
                $viewer->tiers,
            ));
        }

        return [
            $unit->sku,
            $unit instanceof ProductVariant ? $unit->barcode : null,
            $name,
            $category,
            $product->brand,
            $product->unit,
            $this->stock($unit, $display),
            $price,
            $tiers === '' ? null : $tiers,
        ];
    }

    /**
     * Stock by the display rule of the group (CG-27): the number, «В наличии» / «Нет в наличии», or «Мало»; empty when
     * the stock is not tracked.
     */
    private function stock(Stockable $unit, StockDisplay $display): string|int|null
    {
        $available = $unit->availableQty();

        if (! $this->sales->stock_tracking || $available === null) {
            return null;
        }

        if ($available <= 0) {
            return __('storefront.availability.out');
        }

        return match (true) {
            $display === StockDisplay::Exact => $available,
            $display === StockDisplay::Low && $available <= $this->sales->low_stock_threshold => __('storefront.availability.low'),
            default => __('storefront.availability.in'),
        };
    }
}
