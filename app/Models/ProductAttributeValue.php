<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A value of a characteristic on a product (FL-03): `attribute_value_id` for lists, `value_number` (decimal(14,4),
 * a string) for numbers, `value_bool` for booleans. Written only through AttributeService.
 *
 * @property int $id
 * @property int $product_id
 * @property int $attribute_id
 * @property int|null $attribute_value_id
 * @property string|null $value_number
 * @property bool|null $value_bool
 * @property-read Product $product
 * @property-read Attribute $attribute
 * @property-read AttributeValue|null $value
 */
class ProductAttributeValue extends Model
{
    /** @use HasFactory<ProductAttributeValueFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'attribute_id',
        'attribute_value_id',
        'value_number',
        'value_bool',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'attribute_id' => 'integer',
            'attribute_value_id' => 'integer',
            'value_number' => 'decimal:4',
            'value_bool' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * @return BelongsTo<AttributeValue, $this>
     */
    public function value(): BelongsTo
    {
        return $this->belongsTo(AttributeValue::class, 'attribute_value_id');
    }
}
