<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Exceptions\VariantException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Variants\OrderItemSnapshot;
use App\Services\Variants\VariantLabel;
use App\Services\Variants\VariantService;
use App\Settings\CatalogSettings;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->service = app(VariantService::class);
    $this->color = optionWith('Цвет', ['Красный', 'Синий'], color: true);
    $this->size = optionWith('Размер', ['S', 'M', 'L']);
});

function plainProduct(array $attributes = []): Product
{
    return sellable($attributes);
}

it('sets 1 to 3 axes in order and refuses the rest (VR-02)', function (): void {
    $product = plainProduct();

    $this->service->setAxes($product, [$this->size->id, $this->color->id]);
    expect($this->service->axes($product)->pluck('name')->all())->toBe(['Размер', 'Цвет']);

    $this->service->setAxes($product, [$this->color->id, $this->size->id]);
    expect($this->service->axes($product)->pluck('name')->all())->toBe(['Цвет', 'Размер']);

    expect(fn () => $this->service->setAxes($product, []))->toThrow(VariantException::class);

    $extra = [optionWith('A', ['1']), optionWith('B', ['1'])];
    expect(fn () => $this->service->setAxes($product, [$this->color->id, $this->size->id, $extra[0]->id, $extra[1]->id]))
        ->toThrow(VariantException::class)
        ->and(fn () => $this->service->setAxes($product, [999999]))->toThrow(VariantException::class);
});

it('keeps the axis set while variants exist, but allows reordering', function (): void {
    $card = variantCard([], $this->color, $this->size);
    variantOf($card, [$this->color->values[0], $this->size->values[0]]);

    $this->service->setAxes($card, [$this->size->id, $this->color->id]);
    expect($this->service->axes($card)->pluck('name')->all())->toBe(['Размер', 'Цвет']);

    expect(fn () => $this->service->setAxes($card, [$this->size->id]))->toThrow(VariantException::class, 'удалите');
});

it('creates a variant with one value per axis and builds the combination hash (VR-03, VR-04)', function (): void {
    $card = variantCard([], $this->color, $this->size);
    $red = $this->color->values[0];
    $m = $this->size->values[1];

    $variant = $this->service->create($card, [
        'values' => [$red->id, $m->id], 'sku' => ' TS-RED-M ', 'barcode' => '4600000000001',
        'base_price' => '1 290,00', 'stock_qty' => 7,
    ]);

    expect($variant->sku)->toBe('TS-RED-M')
        ->and($variant->combo_hash)->toBe(sha1(min($red->id, $m->id).','.max($red->id, $m->id)))
        ->and($variant->base_price)->toBe('1290.00')
        ->and($variant->final_price)->toBe('1290.00')
        ->and($variant->fresh()->stock_qty)->toBe(7)
        ->and($variant->values()->count())->toBe(2);

    // Prices left empty inherit the card.
    $inherit = $this->service->create($card, ['values' => [$red->id, $this->size->values[0]->id], 'sku' => 'TS-RED-S']);
    expect($inherit->base_price)->toBeNull()->and($inherit->final_price)->toBe('1000.00');
});

it('refuses wrong value sets, duplicate combinations and invalid data', function (): void {
    $card = variantCard([], $this->color, $this->size);
    $red = $this->color->values[0];
    $blue = $this->color->values[1];
    $s = $this->size->values[0];
    $this->service->create($card, ['values' => [$red->id, $s->id], 'sku' => 'A']);

    expect(fn () => $this->service->create($card, ['values' => [$red->id], 'sku' => 'B']))->toThrow(VariantException::class)
        ->and(fn () => $this->service->create($card, ['values' => [$red->id, $blue->id], 'sku' => 'B']))->toThrow(VariantException::class)
        ->and(fn () => $this->service->create($card, ['values' => [$s->id, $red->id], 'sku' => 'C']))->toThrow(VariantException::class, 'уже есть')
        ->and(fn () => $this->service->create($card, ['values' => [$blue->id, $s->id], 'sku' => '  ']))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->create($card, ['values' => [$blue->id, $s->id], 'sku' => 'A']))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->create($card, ['values' => [$blue->id, $s->id], 'sku' => 'D', 'base_price' => 'abc']))->toThrow(VariantException::class)
        ->and(fn () => $this->service->create($card, ['values' => [$blue->id, $s->id], 'sku' => 'D', 'stock_qty' => -1]))->toThrow(VariantException::class);

    $plain = plainProduct();
    expect(fn () => $this->service->create($plain, ['values' => [$red->id], 'sku' => 'E']))->toThrow(VariantException::class);
});

it('enforces the variant limit per card (VR-02, setting max_variants_per_product)', function (): void {
    $settings = app(CatalogSettings::class);
    expect($settings->max_variants_per_product)->toBe(100)->and($settings->create_options_on_import)->toBeTrue();

    $settings->max_variants_per_product = 2;
    $settings->save();

    $card = variantCard([], $this->size);
    $this->service->create($card, ['values' => [$this->size->values[0]->id], 'sku' => 'L1']);
    $this->service->create($card, ['values' => [$this->size->values[1]->id], 'sku' => 'L2']);

    expect(fn () => $this->service->create($card, ['values' => [$this->size->values[2]->id], 'sku' => 'L3']))
        ->toThrow(VariantException::class, 'Слишком много');
    expect($card->variants()->count())->toBe(2);
});

it('updates a variant: sku, values, prices and stock (VR-03)', function (): void {
    $card = variantCard([], $this->size);
    $variant = $this->service->create($card, ['values' => [$this->size->values[0]->id], 'sku' => 'U1', 'stock_qty' => 5]);

    $updated = $this->service->update($variant, [
        'sku' => 'U1-NEW', 'fixed_price' => '500', 'barcode' => '', 'values' => [$this->size->values[1]->id], 'stock_qty' => null, 'is_active' => false,
    ]);

    expect($updated->sku)->toBe('U1-NEW')
        ->and($updated->final_price)->toBe('500.00')
        ->and($updated->barcode)->toBeNull()
        ->and($updated->stock_qty)->toBeNull()
        ->and($updated->is_active)->toBeFalse()
        ->and($updated->combo_hash)->toBe(sha1((string) $this->size->values[1]->id))
        ->and($updated->values()->pluck('option_value_id')->all())->toBe([$this->size->values[1]->id]);

    $other = $this->service->create($card, ['values' => [$this->size->values[2]->id], 'sku' => 'U2']);
    expect(fn () => $this->service->update($other, ['values' => [$this->size->values[1]->id]]))->toThrow(VariantException::class);
});

it('recreates a combination of a variant that was deleted on its own', function (): void {
    $card = variantCard([], $this->size);
    $first = $this->service->create($card, ['values' => [$this->size->values[0]->id], 'sku' => 'R1']);
    $this->service->delete($first);

    expect(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(0);

    $again = $this->service->create($card, ['values' => [$this->size->values[0]->id], 'sku' => 'R2']);

    expect($again->exists)->toBeTrue()->and(ProductVariant::withTrashed()->where('product_id', $card->id)->count())->toBe(1);
});

it('duplicates a variant under other values with the SKU {sku}-copy and zero stock', function (): void {
    $card = variantCard([], $this->size);
    $source = $this->service->create($card, ['values' => [$this->size->values[0]->id], 'sku' => 'D1', 'fixed_price' => '700', 'barcode' => '123', 'stock_qty' => 9]);

    $copy = $this->service->duplicate($source, [$this->size->values[1]->id]);
    $second = $this->service->duplicate($source, [$this->size->values[2]->id]);

    expect($copy->sku)->toBe('D1-copy')
        ->and($second->sku)->toBe('D1-copy-2')
        ->and($copy->fresh()->stock_qty)->toBe(0)
        ->and($copy->barcode)->toBeNull()
        ->and($copy->fixed_price)->toBe('700.00');

    expect(fn () => $this->service->duplicate($source, [$this->size->values[0]->id]))->toThrow(VariantException::class);
});

it('bulk sets stock, price and activity in batches of 100 (VR-24)', function (): void {
    $card = variantCard([], $this->size);
    $ids = [];

    foreach ($this->size->values as $value) {
        $ids[] = $this->service->create($card, ['values' => [$value->id], 'sku' => 'B-'.$value->id, 'stock_qty' => 1])->id;
    }

    expect($this->service->bulkSetStock($ids, 4))->toBe(3)
        ->and(ProductVariant::query()->pluck('stock_qty')->unique()->all())->toBe([4])
        ->and($card->fresh()->stock_qty)->toBe(12);

    expect($this->service->bulkSetPrice($ids, 'fixed_price', '300'))->toBe(3)
        ->and($card->fresh()->final_price)->toBe('300.00');

    expect($this->service->bulkSetPrice($ids, 'fixed_price', null))->toBe(3)
        ->and($card->fresh()->final_price)->toBe('1000.00');

    expect($this->service->bulkSetActive([$ids[0]], false))->toBe(1)
        ->and($card->fresh()->stock_qty)->toBe(8);

    expect(fn () => $this->service->bulkSetPrice($ids, 'final_price', '1'))->toThrow(VariantException::class);
});

it('works through more than one batch', function (): void {
    $card = variantCard();
    $ids = [];

    for ($i = 0; $i < 105; $i++) {
        $ids[] = ProductVariant::factory()->forProduct($card)->stock(1)->create()->id;
    }

    expect($this->service->bulkSetStock($ids, 2))->toBe(105)
        ->and($card->fresh()->stock_qty)->toBe(210);
});

it('builds the label «Цвет: красный, Размер: M» in axis order (VR-12)', function (): void {
    $card = variantCard([], $this->size, $this->color);
    $variant = variantOf($card, [$this->size->values[1], $this->color->values[0]]);

    expect(app(VariantLabel::class)->for($variant))->toBe('Размер: M, Цвет: Красный')
        ->and($variant->stockName())->toBe($card->name.' (Размер: M, Цвет: Красный)');
});

it('builds the order item snapshot with article, barcode and label (VR-12)', function (): void {
    $card = variantCard([], $this->color, $this->size);
    $variant = variantOf($card, [$this->color->values[0], $this->size->values[1]], ['sku' => 'SNAP-1', 'barcode' => '460']);
    $snapshots = app(OrderItemSnapshot::class);

    expect($snapshots->for($card, $variant))->toBe([
        'product_id' => $card->id,
        'variant_id' => $variant->id,
        'sku' => 'SNAP-1',
        'variant_label' => 'Цвет: Красный, Размер: M',
        'barcode' => '460',
        'name' => $card->name,
        'unit' => 'шт',
    ]);

    $plain = plainProduct();
    expect($snapshots->for($plain))->toMatchArray(['variant_id' => null, 'variant_label' => null, 'barcode' => null, 'sku' => $plain->sku]);

    $item = OrderItem::factory()->create(['order_id' => Order::factory()->create()->id] + $snapshots->for($card, $variant));
    expect($item->variant->is($variant))->toBeTrue()->and($item->variant_label)->toBe('Цвет: Красный, Размер: M');
});

describe('switching variants on and off (VR-25)', function (): void {
    it('enables variants and moves the current price and stock into the first variant', function (): void {
        $product = plainProduct(['base_price' => '800.00', 'stock_qty' => 6, 'sku' => 'TEE']);

        $first = $this->service->enable($product, [$this->size->id], [$this->size->values[1]->id]);

        $fresh = $product->fresh();
        expect($fresh->has_variants)->toBeTrue()
            ->and($product->has_variants)->toBeTrue()
            ->and($first->sku)->toBe('TEE-m')
            ->and($first->base_price)->toBe('800.00')
            ->and($first->fresh()->stock_qty)->toBe(6)
            ->and($fresh->stock_qty)->toBe(6)
            ->and($fresh->final_price)->toBe('800.00')
            ->and($fresh->final_price_max)->toBe('800.00');
    });

    it('enables without a first variant: the card is sold out (stock 0), not untracked, until variants exist (D-114)', function (): void {
        $product = plainProduct(['stock_qty' => 3]);

        expect($this->service->enable($product, [$this->color->id]))->toBeNull();

        expect($product->fresh()->has_variants)->toBeTrue()
            ->and($product->fresh()->stock_qty)->toBe(0)
            ->and($product->stock_qty)->toBe(0);
    });

    it('refuses to enable and disable while unfinished requests contain the product and lists their numbers', function (): void {
        sales(['stock_tracking' => true]);
        $product = plainProduct();
        $order = Order::factory()->order()->create(['number' => 'Z-00007']);
        OrderItem::factory()->forProduct($product)->create(['order_id' => $order->id]);
        $reservation = Order::factory()->reservation()->status(OrderStatus::Reserved)->create(['number' => 'R-00003']);
        OrderItem::factory()->forProduct($product)->create(['order_id' => $reservation->id]);
        $done = Order::factory()->order()->status(OrderStatus::Completed)->create(['number' => 'Z-00001']);
        OrderItem::factory()->forProduct($product)->create(['order_id' => $done->id]);
        $inquiry = Order::factory()->inquiry()->create(['number' => 'Q-00001']);
        OrderItem::factory()->forProduct($product)->create(['order_id' => $inquiry->id]);

        try {
            $this->service->enable($product, [$this->size->id]);
            $this->fail('Expected VariantException');
        } catch (VariantException $e) {
            expect($e->orderNumbers)->toBe(['R-00003', 'Z-00007'])
                ->and($e->getMessage())->toContain('R-00003', 'Z-00007')->not->toContain('Z-00001')->not->toContain('Q-00001');
        }

        expect($product->fresh()->has_variants)->toBeFalse();

        // The same for a card with variants.
        Product::query()->whereKey($product->id)->update(['has_variants' => true]);
        expect(fn () => $this->service->disable($product->fresh()))->toThrow(VariantException::class, 'Z-00007');

        $order->update(['status' => OrderStatus::Cancelled]);
        $reservation->update(['status' => OrderStatus::Expired]);
        $this->service->disable($product->fresh());
        expect($product->fresh()->has_variants)->toBeFalse();
    });

    it('does not count deleted requests', function (): void {
        $product = plainProduct();
        $order = Order::factory()->order()->create();
        OrderItem::factory()->forProduct($product)->create(['order_id' => $order->id]);
        $order->delete();

        expect($this->service->unfinishedRequestNumbers($product))->toBe([]);
    });

    it('disables variants: removes variants and axes, prices the card again and keeps the stock aggregate', function (): void {
        $card = variantCard(['base_price' => '900.00'], $this->size);
        $variant = $this->service->create($card, ['values' => [$this->size->values[0]->id], 'sku' => 'X1', 'fixed_price' => '100', 'stock_qty' => 4]);
        CartItem::factory()->forVariant($variant)->create();
        expect($card->fresh()->final_price)->toBe('100.00');

        $this->service->disable($card);

        $fresh = $card->fresh();
        expect($fresh->has_variants)->toBeFalse()
            ->and($fresh->final_price)->toBe('900.00')
            ->and($fresh->final_price_max)->toBeNull()
            ->and($fresh->stock_qty)->toBe(4)
            ->and(ProductVariant::withTrashed()->count())->toBe(0)
            ->and(CartItem::query()->count())->toBe(0)
            ->and($this->service->axes($card)->count())->toBe(0);
    });
});
