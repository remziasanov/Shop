<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoLinkTargetType;
use App\Services\Promo\PromoLinkResolver;
use Carbon\CarbonInterface;
use Database\Factories\PromoLinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Short link `/go/{slug}` to a product, category or internal page (PM-21). Target: `target_id` is the product or
 * category id, `variant_id` the variant of a `variant` link, `target_url` the internal path (+ query) of a
 * `catalog_filter` / `url` link. The decision logic is in {@see PromoLinkResolver}.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property PromoLinkTargetType $target_type
 * @property int|null $target_id
 * @property int|null $variant_id
 * @property string|null $target_url
 * @property int|null $promo_code_id
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_term
 * @property string|null $utm_content
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property bool $is_active
 * @property int|null $created_by
 * @property-read PromoCode|null $promoCode
 */
class PromoLink extends Model
{
    /** @use HasFactory<PromoLinkFactory> */
    use HasFactory;

    public const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    protected $fillable = [
        'slug', 'name', 'target_type', 'target_id', 'variant_id', 'target_url', 'promo_code_id',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'starts_at', 'ends_at', 'is_active', 'created_by',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'target_type' => PromoLinkTargetType::class,
            'target_id' => 'integer',
            'variant_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }

    /**
     * @return HasMany<PromoLinkStat, $this>
     */
    public function stats(): HasMany
    {
        return $this->hasMany(PromoLinkStat::class);
    }

    /**
     * The five UTM values that are set.
     *
     * @return array<string, string>
     */
    public function utm(): array
    {
        $utm = [];

        foreach (self::UTM_KEYS as $key) {
            $value = $this->{$key};

            if (is_string($value) && $value !== '') {
                $utm[$key] = $value;
            }
        }

        return $utm;
    }
}
