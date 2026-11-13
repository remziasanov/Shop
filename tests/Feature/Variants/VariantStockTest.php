<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Orders\OrderService;
use App\Services\Sales\AvailabilityService;
use App\Services\Stock\StockService;
use App\Services\Variants\VariantAggregator;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'reservation_hours' => 48]);
    $this->stock = app(StockService::class);
    $this->size = optionWith('Размер', ['S', 'M', 'L']);
    $this->card = variantCard([], $this->size);
    $this->s = variantOf($this->card, [$this->size->values[0]], ['stock_qty' => 5]);
    $this->m = variantOf($this->card, [$this->size->values[1]], ['stock_qty' => 3]);
});

it('rolls the card aggregates up when variants are created (VR-06)', function (): void {
    expect($this->card->fresh()->stock_qty)->toBe(8)->and($this->card->fresh()->reserved_qty)->toBe(0);
});

it('buying a variant decrements that variant and the card aggregate (VR-15)', function (): void {
    $order = variantOrder(OrderType::Order, $this->card, $this->s, 2);

    $this->stock->deductForOrder($order);

    expect($this->s->fresh()->stock_qty)->toBe(3)
        ->and($this->m->fresh()->stock_qty)->toBe(3)
        ->and($this->card->fresh()->stock_qty)->toBe(6)
        ->and($order->fresh()->stock_applied)->toBeTrue()
        ->and($order->items->first()->fresh()->stock_applied_qty)->toBe(2);
});

it('is idempotent and returns exactly the deducted quantity on cancel', function (): void {
    $order = variantOrder(OrderType::Order, $this->card, $this->s, 4);

    $this->stock->deductForOrder($order);
    $this->stock->deductForOrder($order);
    expect($this->s->fresh()->stock_qty)->toBe(1)->and($this->card->fresh()->stock_qty)->toBe(4);

    $this->stock->returnForOrder($order);
    $this->stock->returnForOrder($order);
    expect($this->s->fresh()->stock_qty)->toBe(5)->and($this->card->fresh()->stock_qty)->toBe(8);
});

it('rejects the second order for the last unit of a variant (check under lock on fresh data)', function (): void {
    $this->s->forceFill(['stock_qty' => 1])->saveQuietly();
    $first = variantOrder(OrderType::Order, $this->card, $this->s, 1);
    // The second request works with a stale model that still shows one unit.
    $stale = $this->s->fresh();
    $second = variantOrder(OrderType::Order, $this->card, $stale, 1);

    $this->stock->deductForOrder($first);

    try {
        $this->stock->deductForOrder($second);
        $this->fail('Expected InsufficientStockException');
    } catch (InsufficientStockException $e) {
        expect($e->available)->toBe(0)
            ->and($e->product->is($this->card))->toBeTrue()
            ->and($e->getMessage())->toContain('Размер: S');
    }

    expect($this->s->fresh()->stock_qty)->toBe(0)
        ->and($this->m->fresh()->stock_qty)->toBe(3)
        ->and($second->fresh()->stock_applied)->toBeFalse()
        ->and($this->card->fresh()->stock_qty)->toBe(3);
});

it('keeps the card aggregate unchanged when an order is refused', function (): void {
    $order = variantOrder(OrderType::Order, $this->card, $this->m, 9);

    expect(fn () => $this->stock->deductForOrder($order))->toThrow(InsufficientStockException::class);
    expect($this->card->fresh()->stock_qty)->toBe(8);
});

it('deducts several variants of one card and a plain product in one order', function (): void {
    $plain = sellable(['stock_qty' => 4]);
    $order = variantOrder(OrderType::Order, $this->card, $this->s, 1);
    $order->items()->create(['product_id' => $this->card->id, 'variant_id' => $this->m->id, 'sku' => 'x', 'name' => 'x', 'unit' => 'шт', 'price' => '1.00', 'qty' => 2, 'total' => '2.00']);
    $order->items()->create(['product_id' => $plain->id, 'sku' => 'y', 'name' => 'y', 'unit' => 'шт', 'price' => '1.00', 'qty' => 3, 'total' => '3.00']);

    $this->stock->deductForOrder($order->fresh());

    expect($this->s->fresh()->stock_qty)->toBe(4)
        ->and($this->m->fresh()->stock_qty)->toBe(1)
        ->and($plain->fresh()->stock_qty)->toBe(1)
        ->and($this->card->fresh()->stock_qty)->toBe(5);
});

it('holds, releases and completes a reservation of a variant (VR-14, Appendix B)', function (): void {
    $order = variantOrder(OrderType::Reservation, $this->card, $this->s, 2);

    $this->stock->holdForReservation($order);
    expect($this->s->fresh()->reserved_qty)->toBe(2)
        ->and($this->s->fresh()->stock_qty)->toBe(5)
        ->and($this->card->fresh()->reserved_qty)->toBe(2)
        ->and($this->card->fresh()->stock_qty)->toBe(8);

    $this->stock->releaseReservation($order);
    expect($this->s->fresh()->reserved_qty)->toBe(0)->and($this->card->fresh()->reserved_qty)->toBe(0);

    $again = variantOrder(OrderType::Reservation, $this->card, $this->s, 3);
    $this->stock->holdForReservation($again);
    $this->stock->completeReservation($again);

    expect($this->s->fresh()->stock_qty)->toBe(2)
        ->and($this->s->fresh()->reserved_qty)->toBe(0)
        ->and($this->card->fresh()->stock_qty)->toBe(5)
        ->and($this->card->fresh()->reserved_qty)->toBe(0);
});

it('refuses to hold more than a variant has available', function (): void {
    $this->s->forceFill(['reserved_qty' => 4])->saveQuietly();
    $order = variantOrder(OrderType::Reservation, $this->card, $this->s, 2);

    expect(fn () => $this->stock->holdForReservation($order))->toThrow(InsufficientStockException::class);
    expect($this->s->fresh()->reserved_qty)->toBe(4);
});

it('releases the hold of an expired reservation of a variant (SF-53)', function (): void {
    $order = app(OrderService::class);
    $reservation = variantOrder(OrderType::Reservation, $this->card, $this->s, 2);
    $reservation->forceFill(['reserved_until' => now()->subHour()])->save();
    $this->stock->holdForReservation($reservation);
    expect($this->card->fresh()->reserved_qty)->toBe(2);

    expect($order->expireReservations())->toBe(1);

    expect($reservation->fresh()->status)->toBe(OrderStatus::Expired)
        ->and($this->s->fresh()->reserved_qty)->toBe(0)
        ->and($this->card->fresh()->reserved_qty)->toBe(0)
        ->and($this->card->fresh()->stock_qty)->toBe(8);
});

it('returns stock when an order is cancelled through the order service (return)', function (): void {
    $service = app(OrderService::class);
    $order = variantOrder(OrderType::Order, $this->card, $this->m, 3);
    $this->stock->deductForOrder($order);
    expect($this->card->fresh()->stock_qty)->toBe(5);

    $service->changeStatus($order->fresh(), OrderStatus::Cancelled);

    expect($this->m->fresh()->stock_qty)->toBe(3)->and($this->card->fresh()->stock_qty)->toBe(8);
});

it('settles a variant order when the request is deleted (D-051)', function (): void {
    $order = variantOrder(OrderType::Reservation, $this->card, $this->s, 2);
    $this->stock->holdForReservation($order);

    app(OrderService::class)->delete($order->fresh());

    expect($this->s->fresh()->reserved_qty)->toBe(0)->and($this->card->fresh()->reserved_qty)->toBe(0);
});

it('still releases the stock of a soft-deleted variant', function (): void {
    $order = variantOrder(OrderType::Reservation, $this->card, $this->s, 2);
    $this->stock->holdForReservation($order);
    $this->s->delete();

    $this->stock->releaseReservation($order);

    expect(ProductVariant::withTrashed()->find($this->s->id)->reserved_qty)->toBe(0)
        ->and($this->card->fresh()->reserved_qty)->toBe(0);
});

it('does nothing for a line without variant on a card with variants', function (): void {
    $order = variantOrder(OrderType::Order, $this->card, null, 2);

    $this->stock->deductForOrder($order);

    expect($this->card->fresh()->stock_qty)->toBe(8)->and($order->fresh()->stock_applied)->toBeTrue();
});

it('does nothing when stock tracking is off', function (): void {
    sales(['stock_tracking' => false]);
    $order = variantOrder(OrderType::Order, $this->card, $this->s, 2);

    $this->stock->deductForOrder($order);

    expect($this->s->fresh()->stock_qty)->toBe(5)->and($order->fresh()->stock_applied)->toBeFalse()
        ->and($this->stock->available($this->s))->toBeNull();
});

describe('aggregate rules (19.5)', function (): void {
    it('is NULL when an active variant is not tracked', function (): void {
        $l = variantOf($this->card, [$this->size->values[2]], ['stock_qty' => null]);

        expect($this->card->fresh()->stock_qty)->toBeNull();

        $l->update(['is_active' => false]);
        expect($this->card->fresh()->stock_qty)->toBe(8);

        $l->update(['is_active' => true]);
        $this->stock->setStock($l->fresh(), 2);
        expect($this->card->fresh()->stock_qty)->toBe(10);
    });

    it('is NULL when no variant is tracked, 0 when none is active (sold out), and reserved sums only active variants', function (): void {
        $this->stock->setStock($this->s, null);
        $this->stock->setStock($this->m, null);
        expect($this->card->fresh()->stock_qty)->toBeNull();

        $this->stock->setStock($this->s, 5);
        $this->s->forceFill(['reserved_qty' => 2])->saveQuietly();
        $this->stock->setStock($this->m, 3);
        $this->m->forceFill(['reserved_qty' => 1])->saveQuietly();
        app(VariantAggregator::class)->refresh($this->card->id);
        expect($this->card->fresh()->reserved_qty)->toBe(3);

        $this->m->update(['is_active' => false]);
        $this->s->update(['is_active' => false]);
        // VR-08, D-114: no active variant = sold out, never "not tracked".
        expect($this->card->fresh()->stock_qty)->toBe(0)->and($this->card->fresh()->reserved_qty)->toBe(0);
    });

    it('follows soft deletion of a variant', function (): void {
        $this->m->delete();

        expect($this->card->fresh()->stock_qty)->toBe(5);
    });

    it('setStock updates the variant and the aggregate and refuses a card with variants', function (): void {
        $this->stock->setStock($this->s, 20);

        expect($this->s->stock_qty)->toBe(20)
            ->and($this->s->fresh()->stock_qty)->toBe(20)
            ->and($this->card->fresh()->stock_qty)->toBe(23);

        $this->stock->setStock($this->s, -5);
        expect($this->s->fresh()->stock_qty)->toBe(0);

        expect(fn () => $this->stock->setStock($this->card->fresh(), 5))->toThrow(LogicException::class);
    });
});

it('locks products before variants in ascending order inside the operation (19.5)', function (): void {
    $other = variantCard([], $this->size);
    $x = variantOf($other, [$this->size->values[0]], ['stock_qty' => 5]);
    $order = variantOrder(OrderType::Order, $this->card, $this->s, 1);
    $order->items()->create(['product_id' => $other->id, 'variant_id' => $x->id, 'sku' => 'q', 'name' => 'q', 'unit' => 'шт', 'price' => '1.00', 'qty' => 1, 'total' => '1.00']);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = str_replace('`', '"', $query->sql);
    });

    $this->stock->deductForOrder($order->fresh());

    // SQLite ignores FOR UPDATE, so the order is read from the shape of the locking queries.
    $position = fn (string $pattern): int|false => array_key_first(array_filter($queries, fn (string $sql): bool => (bool) preg_match($pattern, $sql)));

    $orders = $position('/^select \* from "orders" where "orders"\."id" = \? /');
    $products = $position('/^select \* from "products" where "id" in \(\?, \?\) order by "id" asc/');
    $variants = $position('/^select \* from "product_variants" where "id" in \(\?, \?\) order by "id" asc/');

    expect($orders)->not->toBeFalse()
        ->and($products)->not->toBeFalse()
        ->and($variants)->not->toBeFalse()
        ->and($orders)->toBeLessThan($products)
        ->and($products)->toBeLessThan($variants);
});

it('treats availability on the variant, the sales mode on the card (VR-13)', function (): void {
    $availability = app(AvailabilityService::class);
    $this->s->forceFill(['stock_qty' => 0])->saveQuietly();

    $soldOut = $availability->actionsFor(ProductVariant::query()->with('product')->find($this->s->id));
    $inStock = $availability->actionsFor(ProductVariant::query()->with('product')->find($this->m->id));

    expect($soldOut->canAddToCart)->toBeFalse()->and($soldOut->outOfStock)->toBeTrue()
        ->and($inStock->canAddToCart)->toBeTrue()->and($inStock->maxQty)->toBe(3);

    $this->card->forceFill(['sales_mode' => SalesMode::Showcase])->saveQuietly();
    $showcase = $availability->actionsFor(ProductVariant::query()->with('product')->find($this->m->id));
    expect($showcase->canAddToCart)->toBeFalse();
});

it('still works for products without variants exactly as before', function (): void {
    $product = Product::factory()->create(['stock_qty' => 3]);
    $order = variantOrder(OrderType::Order, $product, null, 2);

    $this->stock->deductForOrder($order);

    expect($product->fresh()->stock_qty)->toBe(1)->and($product->fresh()->final_price_max)->toBeNull();
});
