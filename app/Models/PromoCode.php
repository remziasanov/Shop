<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoScope;
use App\Enums\PromoType;
use App\Services\Promo\PromoCodeNormalizer;
use App\Services\Promo\PromoRedemptionService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\PromoCodeFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Promo code (PM-01…PM-06, 22.5). The code is stored upper-cased and trimmed; money columns are decimal(12,2) strings
 * (use {@see Money}); `value` is a percent for `percent` and rubles for `fixed`. `usage_count` is maintained by
 * {@see PromoRedemptionService} only (atomic UPDATE under lock, PM-11).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property PromoType $type
 * @property string|null $value
 * @property PromoScope $scope
 * @property bool $include_subcategories
 * @property string|null $min_order_amount
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property int|null $usage_limit
 * @property int $usage_count
 * @property int|null $per_customer_limit
 * @property bool $only_registered
 * @property bool $applies_to_discounted
 * @property bool $stackable_with_promotions
 * @property bool $is_active
 * @property int|null $batch_id
 * @property int|null $created_by
 * @property-read Collection<int, CustomerGroup> $groups
 * @property-read Collection<int, PromoCodeTarget> $targets
 * @property-read PromoCodeBatch|null $batch
 */
class PromoCode extends Model
{
    /** @use HasFactory<PromoCodeFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'type', 'value', 'scope', 'include_subcategories', 'min_order_amount', 'starts_at', 'ends_at',
        'usage_limit', 'per_customer_limit', 'only_registered', 'applies_to_discounted', 'stackable_with_promotions',
        'is_active', 'batch_id', 'created_by',
    ];

    protected static function booted(): void
    {
        // PM-19: a code with redemptions can only be deactivated.
        static::deleting(function (PromoCode $code): void {
            if ($code->redemptions()->exists()) {
                throw new LogicException(__('promo.errors.has_redemptions'));
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => PromoType::class,
            'scope' => PromoScope::class,
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'include_subcategories' => 'boolean',
            'only_registered' => 'boolean',
            'applies_to_discounted' => 'boolean',
            'stackable_with_promotions' => 'boolean',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'usage_count' => 'integer',
            'per_customer_limit' => 'integer',
        ];
    }

    /**
     * PM-01: always stored upper-case without surrounding spaces, so the unique index is case-insensitive.
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => PromoCodeNormalizer::normalize($value));
    }

    /**
     * The code with this (user typed) text, case and surrounding spaces ignored.
     */
    public static function findByCode(string $input): ?self
    {
        $code = PromoCodeNormalizer::normalize($input);

        return $code === '' ? null : self::query()->where('code', $code)->first();
    }

    /**
     * Ids of the groups a new code is available to by default (PM-04): «Гость» and «Розница».
     *
     * @return list<int>
     */
    public static function defaultGroupIds(): array
    {
        return CustomerGroup::query()
            ->whereIn('code', [CustomerGroup::GUEST, CustomerGroup::RETAIL])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return BelongsToMany<CustomerGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'promo_code_groups', 'promo_code_id', 'customer_group_id');
    }

    /**
     * @return HasMany<PromoCodeTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PromoCodeTarget::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<PromoCodeBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PromoCodeBatch::class, 'batch_id');
    }

    /**
     * @return HasMany<PromoRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    /**
     * Fixed discount or minimum order in kopecks.
     */
    public function minOrderKopecks(): ?int
    {
        return $this->min_order_amount === null ? null : Money::fromRubles($this->min_order_amount);
    }
}
