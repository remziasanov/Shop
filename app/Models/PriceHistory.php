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
 * @property string $field
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

    protected $fillable = ['product_id', 'variant_id', 'field', 'old_value', 'new_value', 'source', 'user_id', 'import_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'decimal:2',
            'new_value' => 'decimal:2',
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
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class)->withTrashed();
    }
}
