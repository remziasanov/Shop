<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ProductVariantObserver;
use App\Services\Pricing\Priceable;
use App\Services\Stock\Stockable;
use App\Services\Variants\VariantLabel;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A variant of a product card (VR-03): own SKU, barcode, stock, reservation and optional own prices (null = inherit the
 * card). `final_price`, `display_old_price`, `price_calculated_at` are maintained by the observer, `combo_hash` by
 * VariantService, `stock_qty` / `reserved_qty` by StockService; none of them is mass assignable.
 *
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property string|null $barcode
 * @property string $combo_hash
 * @property string|null $base_price
 * @property string|null $cost_price
 * @property string|null $compare_at_price
 * @property string|null $fixed_price
 * @property string $final_price
 * @property string|null $display_old_price
 * @property CarbonInterface|null $price_calculated_at
 * @property int|null $stock_qty
 * @property int $reserved_qty
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonInterface|null $deleted_at
 * @property-read Product $product
 * @property-read Collection<int, ProductVariantValue> $values
 */
#[ObservedBy(ProductVariantObserver::class)]
class ProductVariant extends Model implements Priceable, Stockable
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'base_price',
        'cost_price',
        'compare_at_price',
        'fixed_price',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'fixed_price' => 'decimal:2',
            'final_price' => 'decimal:2',
            'display_old_price' => 'decimal:2',
            'price_calculated_at' => 'datetime',
            'stock_qty' => 'integer',
            'reserved_qty' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The card; also works for a soft-deleted card.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * Values on the axes (one per axis).
     *
     * @return HasMany<ProductVariantValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ProductVariantValue::class, 'variant_id');
    }

    /**
     * The card. Eager-load `product` in lists; without it one query loads the card (also a soft-deleted one).
     */
    public function stockProduct(): Product
    {
        if (! $this->relationLoaded('product')) {
            $this->setRelation('product', Product::withTrashed()->findOrFail($this->product_id));
        }

        return $this->product;
    }

    public function priceProductId(): int
    {
        return $this->product_id;
    }

    public function priceVariantId(): ?int
    {
        return $this->id;
    }

    public function retailPriceKopecks(): int
    {
        return Money::fromRubles((string) $this->final_price);
    }

    public function retailOldPriceKopecks(): ?int
    {
        return $this->display_old_price !== null ? Money::fromRubles((string) $this->display_old_price) : null;
    }

    public function stockProductId(): int
    {
        return $this->product_id;
    }

    public function stockVariantId(): ?int
    {
        return $this->id;
    }

    public function availableQty(): ?int
    {
        return $this->stock_qty === null ? null : $this->stock_qty - $this->reserved_qty;
    }

    public function stockName(): string
    {
        $label = app(VariantLabel::class)->for($this);

        return $this->stockProduct()->name.($label === '' ? '' : ' ('.$label.')');
    }
}
