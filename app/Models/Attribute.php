<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttributeType;
use Database\Factories\AttributeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Characteristic of the dictionary (FL-01, 20.4). Values of products are written only through AttributeService.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property AttributeType $type
 * @property string|null $unit
 * @property bool $is_filterable
 * @property bool $index_single_value
 * @property int $sort_order
 * @property bool $is_active
 * @property-read Collection<int, AttributeValue> $values
 */
class Attribute extends Model
{
    /** @use HasFactory<AttributeFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'unit',
        'is_filterable',
        'index_single_value',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'is_filterable' => 'boolean',
            'index_single_value' => 'boolean',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Dictionary values in display order (list type only).
     *
     * @return HasMany<AttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function productValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * @return HasMany<CategoryFilter, $this>
     */
    public function categoryFilters(): HasMany
    {
        return $this->hasMany(CategoryFilter::class);
    }

    /**
     * @param  Builder<Attribute>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Name with the unit, the way the import header and the export write it: "Мощность, Вт" (FL-16).
     */
    public function signature(): string
    {
        return $this->unit !== null && $this->unit !== '' ? $this->name.', '.$this->unit : $this->name;
    }
}
