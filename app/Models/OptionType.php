<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OptionDisplayType;
use App\Services\Catalog\SlugService;
use Carbon\CarbonInterface;
use Database\Factories\OptionTypeFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An option of the dictionary: Color, Size… (VR-01).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property OptionDisplayType $display_type
 * @property bool $is_filterable
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Collection<int, OptionValue> $values
 */
class OptionType extends Model
{
    /** @use HasFactory<OptionTypeFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'display_type', 'is_filterable', 'sort_order', 'is_active'];

    protected static function booted(): void
    {
        static::saving(function (OptionType $type): void {
            if (! $type->exists || $type->isDirty('slug') || trim((string) $type->slug) === '') {
                $type->slug = app(SlugService::class)->resolve('option_types', $type->slug, (string) $type->name, $type->id);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'display_type' => OptionDisplayType::class,
            'is_filterable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return HasMany<OptionValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(OptionValue::class)->orderBy('sort_order')->orderBy('id');
    }
}
