<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceChangeSource;
use Carbon\CarbonInterface;
use Database\Factories\PriceHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Change of a price input of a product (PR-06): base_price, cost_price, fixed_price, compare_at_price.
 * Rows are append-only; `import_id` references `imports` (FK on MySQL; plain indexed column on SQLite).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id set for a change of a variant price (VR-22)
 * @property int|null $customer_group_id set for a change of an exact group price or a tier (GP-16)
 * @property int|null $min_qty tier quantity (1 = the exact group price)
 * @property string $field price field, or group_price / group_tier
 * @property string|null $old_value
 * @property string|null $new_value
 * @property PriceChangeSource $source
 * @property int|null $user_id
 * @property int|null $import_id
 * @property CarbonInterface|null $created_at
 * @property-read Product $product
 * @property-read User|null $user
 */
class PriceHistory extends Model
{
    /** @use HasFactory<PriceHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'price_history';

    protected $fillable = ['product_id', 'variant_id', 'field', 'old_value', 'new_value', 'source', 'user_id', 'import_id', 'customer_group_id', 'min_qty'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'decimal:2',
            'new_value' => 'decimal:2',
            'min_qty' => 'integer',
            'source' => PriceChangeSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        // The history of a soft-deleted product stays readable.
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
