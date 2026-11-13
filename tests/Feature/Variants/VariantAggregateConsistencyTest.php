<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\VariantException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CatalogQuery;
use App\Services\Dashboard\DashboardStats;
use App\Services\Sales\AvailabilityService;
use App\Services\Stock\StockService;
use App\Services\Variants\CombinationGenerator;
use App\Services\Variants\SkuGuard;
use App\Services\Variants\VariantAggregator;
use App\Services\Variants\VariantService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
    $this->size = optionWith('Размер', ['S', 'M']);
    $this->service = app(VariantService::class);
});

/**
 * The three places that decide «в наличии» for a card: the availability service (list and card), the in-stock filter
 * of the catalog (aggregate columns) and the dashboard's NOT EXISTS count.
 *
 * @return array{actions: bool, filter: bool, dashboard: bool} true = the place treats the card as available
 */
function availabilityVerdicts(Product $card): array
{
    app(DashboardStats::class)->forget();
    $card = $card->fresh();

    return [
        'actions' => ! app(AvailabilityService::class)->actionsFor($card)->outOfStock,
        'filter' => app(CatalogQuery::class)->selection(new CatalogFilters(inStock: true), null)->where('products.id', $card->id)->exists(),
        'dashboard' => app(DashboardStats::class)->products()['out_of_stock'] === 0,
    ];
}

describe('a card without an active variant is sold out, never "not tracked" (VR-08, D-114)', function (): void {
    it('agrees in availability, the in-stock filter and the dashboard', function (string $scenario, bool $available): void {
        $card = variantCard([], $this->size);

        match ($scenario) {
            'no variants yet' => app(VariantAggregator::class)->refresh($card->id),
            'all hidden' => (function () use ($card): void {
                $a = variantOf($card, [$this->size->values[0]], ['stock_qty' => 5]);
                $b = variantOf($card, [$this->size->values[1]], ['stock_qty' => null]);
                $this->service->bulkSetActive([$a->id, $b->id], false);
            })(),
            'tracked in stock' => variantOf($card, [$this->size->values[0]], ['stock_qty' => 5]),
            'tracked sold out' => variantOf($card, [$this->size->values[0]], ['stock_qty' => 0]),
            'untracked' => variantOf($card, [$this->size->values[0]], ['stock_qty' => null]),
            'only the hidden one has stock' => (function () use ($card): void {
                variantOf($card, [$this->size->values[0]], ['stock_qty' => 0]);
                variantOf($card, [$this->size->values[1]], ['stock_qty' => 9, 'is_active' => false]);
            })(),
        };

        expect(availabilityVerdicts($card))->toBe(['actions' => $available, 'filter' => $available, 'dashboard' => $available]);
    })->with([
        ['no variants yet', false],
        ['all hidden', false],
        ['tracked in stock', true],
        ['tracked sold out', false],
        ['untracked', true],
        ['only the hidden one has stock', false],
    ]);

    it('writes 0 / 0 when the last active variant is hidden, deleted or deactivated by bulk', function (): void {
        $card = variantCard([], $this->size);
        $a = variantOf($card, [$this->size->values[0]], ['stock_qty' => 5]);
        $b = variantOf($card, [$this->size->values[1]], ['stock_qty' => 2]);
        $b->forceFill(['reserved_qty' => 1])->saveQuietly();
        app(VariantAggregator::class)->refresh($card->id);

        $this->service->update($a, ['is_active' => false]);
        expect($card->fresh()->stock_qty)->toBe(2)->and($card->fresh()->reserved_qty)->toBe(1);

        $this->service->delete($b);
        expect($card->fresh()->stock_qty)->toBe(0)->and($card->fresh()->reserved_qty)->toBe(0);
    });

    it('is also what shop:variants-rollup computes (no discrepancy for a sold-out card)', function (): void {
        $card = variantCard([], $this->size);
        $this->service->enable(Product::query()->findOrFail($card->id), [$this->size->id]);

        expect(app(VariantAggregator::class)->discrepancies())->toBe([]);

        DB::table('products')->where('id', $card->id)->update(['stock_qty' => null]);
        expect(collect(app(VariantAggregator::class)->discrepancies())->pluck('field')->all())->toBe(['stock_qty']);
    });
});

describe('the rollup runs inside the transaction, after the card row is locked (19.5)', function (): void {
    /**
     * @return list<array{sql: string, level: int}>
     */
    function recordQueries(Closure $work): array
    {
        $log = [];
        DB::listen(function ($query) use (&$log): void {
            $log[] = ['sql' => str_replace('`', '"', $query->sql), 'level' => DB::transactionLevel()];
        });
        $work();

        return $log;
    }

    /**
     * Asserts: the card is read (locked) before the variants are aggregated, and the card's aggregate columns are
     * written inside a transaction.
     *
     * @param  list<array{sql: string, level: int}>  $log
     */
    function assertRollupInsideTransaction(array $log, int $baseLevel): void
    {
        $lock = null;
        $aggregate = null;
        $write = null;

        foreach ($log as $i => $entry) {
            if ($lock === null && preg_match('/^select .* from "products" where "id" = \? and "has_variants" = \? and "deleted_at" is null/', $entry['sql'])) {
                $lock = $i;
            }

            if ($aggregate === null && str_contains($entry['sql'], 'from "product_variants"') && str_contains($entry['sql'], 'group by "product_id"')) {
                $aggregate = $i;
            }

            if (preg_match('/^update "products" set "stock_qty"/', $entry['sql'])) {
                $write = $i;
            }
        }

        expect($lock)->not->toBeNull()->and($aggregate)->not->toBeNull()->and($write)->not->toBeNull()
            ->and($lock)->toBeLessThan($aggregate)
            ->and($log[$write]['level'])->toBeGreaterThan(0)
            ->and($log[$aggregate]['level'])->toBeGreaterThan(0);
    }

    beforeEach(function (): void {
        $this->card = variantCard([], $this->size);
        $this->a = variantOf($this->card, [$this->size->values[0]], ['stock_qty' => 5]);
    });

    it('for create()', function (): void {
        $log = recordQueries(fn () => $this->service->create($this->card, ['values' => [$this->size->values[1]->id], 'sku' => 'NEW-1', 'stock_qty' => 3]));

        assertRollupInsideTransaction($log, 0);
        expect($this->card->fresh()->stock_qty)->toBe(8);
    });

    it('for update()', function (): void {
        $log = recordQueries(fn () => $this->service->update($this->a, ['is_active' => false]));

        assertRollupInsideTransaction($log, 0);
        expect($this->card->fresh()->stock_qty)->toBe(0);
    });

    it('for bulk operations', function (): void {
        $log = recordQueries(fn () => $this->service->bulkSetStock([$this->a->id], 7));

        assertRollupInsideTransaction($log, 0);
        expect($this->card->fresh()->stock_qty)->toBe(7);
    });

    it('for the combination generator', function (): void {
        $log = recordQueries(fn () => app(CombinationGenerator::class)->create($this->card, [['values' => [$this->size->values[1]->id], 'sku' => 'GEN-1']], ['stock_qty' => 4]));

        assertRollupInsideTransaction($log, 0);
        expect($this->card->fresh()->stock_qty)->toBe(9);
    });

    it('for the rollup command and rollupStock()', function (): void {
        DB::table('products')->where('id', $this->card->id)->update(['stock_qty' => 99]);
        $log = recordQueries(fn () => app(VariantAggregator::class)->rollupAll());

        assertRollupInsideTransaction($log, 0);
        expect($this->card->fresh()->stock_qty)->toBe(5);

        DB::table('products')->where('id', $this->card->id)->update(['stock_qty' => 99]);
        $log = recordQueries(fn () => app(VariantAggregator::class)->rollupStock($this->card->id));

        assertRollupInsideTransaction($log, 0);
    });

    it('discards the deferred refresh when the batch fails', function (): void {
        $aggregator = app(VariantAggregator::class);
        DB::table('products')->where('id', $this->card->id)->update(['stock_qty' => 99]);

        try {
            $aggregator->batch(function () use ($aggregator): void {
                $aggregator->refresh($this->card->id);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        expect($this->card->fresh()->stock_qty)->toBe(99);
    });

    it('leaves a trashed card alone and rolls it up when it is restored', function (): void {
        $variant = variantOf($this->card, [$this->size->values[1]], ['stock_qty' => 3]);
        $this->card->fresh()->delete();

        DB::table('products')->where('id', $this->card->id)->update(['stock_qty' => 99]);
        app(VariantAggregator::class)->refresh($this->card->id);
        expect(app(VariantAggregator::class)->rollupStock($this->card->id))->toBeFalse()
            ->and(DB::table('products')->where('id', $this->card->id)->value('stock_qty'))->toBe(99);
        expect(app(VariantAggregator::class)->rollupAll())->toBe([]);

        Product::onlyTrashed()->findOrFail($this->card->id)->restore();

        expect($this->card->fresh()->stock_qty)->toBe(8)->and($variant->fresh()->is_active)->toBeTrue();
    });
});

it('keeps a concurrent checkout: the refresh reads the variants after the card lock, so nothing stale is written', function (): void {
    $card = variantCard([], $this->size);
    $a = variantOf($card, [$this->size->values[0]], ['stock_qty' => 5]);
    $b = variantOf($card, [$this->size->values[1]], ['stock_qty' => 4]);

    // The stale view that a refresh outside the lock would have used: variants as they were before the checkout.
    $order = variantOrder(OrderType::Order, $card, $a, 2);
    app(StockService::class)->deductForOrder($order);

    // A late (deferred) refresh of a change made earlier still computes from the committed rows.
    app(VariantAggregator::class)->refresh($card->id);

    expect($card->fresh()->stock_qty)->toBe(7)->and(app(VariantAggregator::class)->discrepancies())->toBe([]);
});

describe('purging a variant that was deleted on its own', function (): void {
    it('is refused while an unfinished request points at it (VR-25 message)', function (): void {
        $card = variantCard([], $this->size);
        $old = variantOf($card, [$this->size->values[0]], ['sku' => 'OLD']);
        $order = Order::factory()->order()->create(['number' => 'Z-00042']);
        OrderItem::factory()->forVariant($old)->create(['order_id' => $order->id]);
        $this->service->delete($old);

        expect(fn () => $this->service->create($card, ['values' => [$this->size->values[0]->id], 'sku' => 'NEW']))
            ->toThrow(VariantException::class, 'Z-00042');
        expect(ProductVariant::withTrashed()->where('sku', 'OLD')->exists())->toBeTrue();

        $order->update(['status' => OrderStatus::Completed]);
        $again = $this->service->create($card, ['values' => [$this->size->values[0]->id], 'sku' => 'NEW']);

        expect($again->exists)->toBeTrue()->and(ProductVariant::withTrashed()->where('sku', 'OLD')->exists())->toBeFalse();
    });
});

describe('a SKU race on the unique index is an ordinary validation error (D-087)', function (): void {
    it('turns a unique violation on sku (same table) into a ValidationException on sku', function (): void {
        $card = variantCard([], $this->size);
        $other = variantOf($card, [$this->size->values[0]], ['sku' => 'RACE']);
        $variant = variantOf($card, [$this->size->values[1]], ['sku' => 'FREE']);

        // The pre-check cannot see a parallel writer; the unique index of the table is the last line.
        $write = fn () => ProductVariant::query()->whereKey($variant->id)->update(['sku' => 'RACE']);

        try {
            app(SkuGuard::class)->guard($write);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('sku');
        }

        $productRace = fn () => Product::query()->whereKey($card->id)->update(['sku' => Product::factory()->create(['sku' => 'P-RACE'])->sku]);
        expect(fn () => app(SkuGuard::class)->guard($productRace))->toThrow(ValidationException::class);

        expect($other->fresh()->sku)->toBe('RACE')->and($variant->fresh()->sku)->toBe('FREE');
    });

    it('does not swallow other unique violations', function (): void {
        $row = ['name' => 'x', 'slug' => 'same-slug', 'created_at' => now(), 'updated_at' => now()];
        DB::table('option_types')->insert($row);

        expect(fn () => app(SkuGuard::class)->guard(fn () => DB::table('option_types')->insert($row)))
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('the variant service maps a combination race to its own error', function (): void {
        $card = variantCard([], $this->size);
        $first = variantOf($card, [$this->size->values[0]]);
        $second = variantOf($card, [$this->size->values[1]]);

        // Same combination written behind the service's back: the unique(product_id, combo_hash) index refuses it.
        expect(fn () => DB::table('product_variants')->where('id', $second->id)->update(['combo_hash' => $first->combo_hash]))
            ->toThrow(UniqueConstraintViolationException::class);
    });
});
