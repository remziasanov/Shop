<?php

declare(strict_types=1);

use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\ProductDuplicator;
use App\Services\Variants\VariantService;

beforeEach(function (): void {
    $this->color = optionWith('Цвет', ['Красный', 'Синий'], color: true);
    $this->size = optionWith('Размер', ['S', 'M']);
    $this->card = variantCard(['sku' => 'TEE', 'base_price' => '1000.00'], $this->color, $this->size);
    $this->red = variantOf($this->card, [$this->color->values[0], $this->size->values[0]], ['sku' => 'TEE-R-S', 'stock_qty' => 5, 'fixed_price' => '800.00', 'barcode' => '111']);
    $this->blue = variantOf($this->card, [$this->color->values[1], $this->size->values[0]], ['sku' => 'TEE-B-S', 'stock_qty' => 3]);
});

describe('duplicating a card with variants (VR-27)', function (): void {
    it('copies axes and variants with {sku}-copy and zeroed stock', function (): void {
        $copy = app(ProductDuplicator::class)->duplicate($this->card->fresh());

        $variants = ProductVariant::query()->where('product_id', $copy->id)->orderBy('id')->get();

        expect($copy->sku)->toBe('TEE-copy')
            ->and($copy->fresh()->has_variants)->toBeTrue()
            ->and($copy->options()->pluck('name')->all())->toBe(['Цвет', 'Размер'])
            ->and($variants->pluck('sku')->all())->toBe(['TEE-R-S-copy', 'TEE-B-S-copy'])
            ->and($variants->pluck('stock_qty')->all())->toBe([0, 0])
            ->and($variants[0]->fixed_price)->toBe('800.00')
            ->and($variants[0]->combo_hash)->toBe($this->red->combo_hash)
            ->and($variants[0]->values()->count())->toBe(2)
            ->and($variants[0]->final_price)->toBe('800.00')
            ->and($copy->fresh()->stock_qty)->toBe(0)
            ->and($copy->fresh()->final_price)->toBe('800.00')
            ->and($copy->fresh()->final_price_max)->toBe('1000.00');

        // The source is untouched.
        expect($this->red->fresh()->stock_qty)->toBe(5)->and($this->card->fresh()->stock_qty)->toBe(8);
    });

    it('makes variant SKUs of the copy unique among products and variants (VR-04)', function (): void {
        Product::factory()->create(['sku' => 'TEE-R-S-copy']);

        $copy = app(ProductDuplicator::class)->duplicate($this->card->fresh());

        expect(ProductVariant::query()->where('product_id', $copy->id)->orderBy('id')->pluck('sku')->all())->toBe(['TEE-R-S-copy-2', 'TEE-B-S-copy']);

        $again = app(ProductDuplicator::class)->duplicate($this->card->fresh());
        expect($again->sku)->toBe('TEE-copy-2')
            ->and(ProductVariant::query()->where('product_id', $again->id)->orderBy('id')->pluck('sku')->all())->toBe(['TEE-R-S-copy-3', 'TEE-B-S-copy-2']);
    });

    it('keeps untracked variants untracked and still duplicates products without variants', function (): void {
        $this->blue->forceFill(['stock_qty' => null])->saveQuietly();

        $copy = app(ProductDuplicator::class)->duplicate($this->card->fresh());

        expect(ProductVariant::query()->where('product_id', $copy->id)->orderBy('id')->pluck('stock_qty')->all())->toBe([0, null]);

        $plain = Product::factory()->create(['stock_qty' => 4]);
        $plainCopy = app(ProductDuplicator::class)->duplicate($plain);
        expect($plainCopy->has_variants)->toBeFalse()->and($plainCopy->fresh()->final_price_max)->toBeNull();
    });
});

describe('trash and restore of a card (VR-27)', function (): void {
    it('moves the variants to the trash with the card and brings back exactly them', function (): void {
        $gone = variantOf($this->card, [$this->color->values[1], $this->size->values[1]]);
        $this->travel(-2)->hours();
        app(VariantService::class)->delete($gone);
        $this->travelBack();

        $this->card->fresh()->delete();

        expect(ProductVariant::query()->count())->toBe(0)
            ->and(ProductVariant::onlyTrashed()->count())->toBe(3)
            ->and(Product::onlyTrashed()->find($this->card->id))->not->toBeNull();

        Product::onlyTrashed()->find($this->card->id)->restore();

        expect(ProductVariant::query()->where('product_id', $this->card->id)->pluck('sku')->sort()->values()->all())->toBe(['TEE-B-S', 'TEE-R-S'])
            ->and(ProductVariant::onlyTrashed()->count())->toBe(1)
            ->and($this->card->fresh()->stock_qty)->toBe(8)
            ->and($this->card->fresh()->final_price)->toBe('800.00')
            ->and($this->card->fresh()->final_price_max)->toBe('1000.00');
    });

    it('removes the variants for good with the card', function (): void {
        $id = $this->red->id;
        CartItem::factory()->forVariant($this->red)->create();

        $this->card->fresh()->delete();
        Product::onlyTrashed()->find($this->card->id)->forceDelete();

        expect(ProductVariant::withTrashed()->find($id))->toBeNull()
            ->and(CartItem::query()->count())->toBe(0);
    });
});
