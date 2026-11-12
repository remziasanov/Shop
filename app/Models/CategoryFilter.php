<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FilterSource;
use Database\Factories\CategoryFilterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of the filter set of a category (FL-05). `option_type_id` references `option_types` (stage 11, VR-19).
 *
 * @property int $id
 * @property int $category_id
 * @property FilterSource $source
 * @property int|null $attribute_id
 * @property int|null $option_type_id
 * @property int $sort_order
 * @property bool $is_expanded
 * @property-read Category $category
 * @property-read Attribute|null $attribute
 * @property-read OptionType|null $optionType
 */
class CategoryFilter extends Model
{
    /** @use HasFactory<CategoryFilterFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'category_id',
        'source',
        'attribute_id',
        'option_type_id',
        'sort_order',
        'is_expanded',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'source' => FilterSource::class,
            'attribute_id' => 'integer',
            'option_type_id' => 'integer',
            'sort_order' => 'integer',
            'is_expanded' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Attribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }

    /**
     * @return BelongsTo<OptionType, $this>
     */
    public function optionType(): BelongsTo
    {
        return $this->belongsTo(OptionType::class);
    }
}
