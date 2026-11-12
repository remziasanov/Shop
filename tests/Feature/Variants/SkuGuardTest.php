<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Variants\SkuGuard;
use App\Services\Variants\VariantService;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->guard = app(SkuGuard::class);
});

it('finds a SKU taken by a product or by a variant (VR-04)', function (): void {
    $product = Product::factory()->create(['sku' => 'P-1']);
    $card = variantCard();
    ProductVariant::factory()->forProduct($card)->create(['sku' => 'V-1']);

    expect($this->guard->isTaken('P-1'))->toBeTrue()
        ->and($this->guard->isTaken('V-1'))->toBeTrue()
        ->and($this->guard->isTaken('FREE'))->toBeFalse()
        ->and($this->guard->isTaken('P-1', $product))->toBeFalse();
});

it('counts soft-deleted products and variants too', function (): void {
    Product::factory()->create(['sku' => 'P-2'])->delete();
    $variant = ProductVariant::factory()->forProduct(variantCard())->create(['sku' => 'V-2']);
    $variant->delete();

    expect($this->guard->isTaken('P-2'))->toBeTrue()
        ->and($this->guard->isTaken('V-2'))->toBeTrue();
});

it('refuses a variant SKU equal to a product SKU and vice versa', function (): void {
    $size = optionWith('Размер', ['S']);
    $card = variantCard([], $size);
    Product::factory()->create(['sku' => 'TAKEN']);

    expect(fn () => app(VariantService::class)->create($card, ['values' => [$size->values[0]->id], 'sku' => 'TAKEN']))
        ->toThrow(ValidationException::class);

    $variant = app(VariantService::class)->create($card, ['values' => [$size->values[0]->id], 'sku' => 'VAR-SKU']);

    expect(fn () => Product::factory()->create(['sku' => 'VAR-SKU']))->toThrow(ValidationException::class)
        ->and(fn () => app(VariantService::class)->update($variant, ['sku' => 'TAKEN']))->toThrow(ValidationException::class);
});

it('allows a product to be saved again with its own SKU', function (): void {
    $product = Product::factory()->create(['sku' => 'KEEP']);

    $product->update(['name' => 'Новое имя']);

    expect($product->fresh()->name)->toBe('Новое имя');
});

it('proposes the first free copy SKU across both tables', function (): void {
    Product::factory()->create(['sku' => 'A-copy']);
    ProductVariant::factory()->forProduct(variantCard())->create(['sku' => 'A-copy-2']);

    expect($this->guard->firstFree('A', '-copy'))->toBe('A-copy-3');
});

it('reports a SKU used by both tables, as a race would leave it (D-087)', function (): void {
    expect($this->guard->collisions())->toBe([]);

    Product::factory()->create(['sku' => 'DUP']);
    DB::table('product_variants')->insert([
        'product_id' => variantCard()->id, 'sku' => 'DUP', 'combo_hash' => sha1('x'), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect($this->guard->collisions())->toBe(['DUP']);
});
