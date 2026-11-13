<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value a variant has on one axis (composite primary key (variant_id, option_type_id), so read and insert only).
 *
 * @property int $variant_id
 * @property int $option_type_id
 * @property int $option_value_id
 * @property-read OptionType $optionType
 * @property-read OptionValue $optionValue
 */
class ProductVariantValue extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['variant_id', 'option_type_id', 'option_value_id'];

    /**
     * @return BelongsTo<OptionType, $this>
     */
    public function optionType(): BelongsTo
    {
        return $this->belongsTo(OptionType::class);
    }

    /**
     * @return BelongsTo<OptionValue, $this>
     */
    public function optionValue(): BelongsTo
    {
        return $this->belongsTo(OptionValue::class);
    }
}
