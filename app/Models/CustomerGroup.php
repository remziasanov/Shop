<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryAccessMode;
use App\Enums\GroupStockDisplay;
use App\Enums\PriceDisplayMode;
use App\Enums\StorefrontSection;
use App\Observers\CustomerGroupObserver;
use App\Services\Access\CatalogAccess;
use App\Services\Access\CustomerGroupService;
use Database\Factories\CustomerGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Customer group (CG-01…CG-10, CG-27): what a viewer of the storefront sees and may do. «Гость» (`guest`) and
 * «Розница» (`retail`) are system groups created by the migration. The computed access sets live in
 * {@see CatalogAccess}; saving a group bumps the catalog version (CG-19).
 *
 * `min_order_amount` is rubles as a decimal string (D-009), null = the value of the sales settings.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 * @property bool $is_active
 * @property int $sort_order
 * @property list<string> $visible_sections
 * @property CategoryAccessMode $category_access_mode
 * @property PriceDisplayMode $price_display
 * @property list<string>|null $allowed_sales_modes
 * @property string|null $min_order_amount
 * @property list<string>|null $allowed_delivery_codes
 * @property list<string>|null $allowed_payment_codes
 * @property GroupStockDisplay $stock_display
 * @property bool $is_default_for_new CG-11: the group of newly registered customers (exactly one; set by {@see CustomerGroupService})
 * @property bool $require_login_to_order CG-12
 * @property bool $is_wholesale_default CG-22: preselected by a wholesale approval (set by the migration / the service)
 * @property string $default_discount_percent GP-01: discount from the retail price, 0–99.99 (decimal string)
 * @property bool $show_retail_price GP-01: show the retail price struck through next to the group price
 */
#[ObservedBy(CustomerGroupObserver::class)]
#[Fillable([
    'code', 'name', 'description', 'is_active', 'sort_order', 'visible_sections', 'category_access_mode',
    'price_display', 'allowed_sales_modes', 'min_order_amount', 'allowed_delivery_codes', 'allowed_payment_codes',
    'stock_display', 'require_login_to_order', 'default_discount_percent', 'show_retail_price',
])]
class CustomerGroup extends Model
{
    /** @use HasFactory<CustomerGroupFactory> */
    use HasFactory;

    public const GUEST = 'guest';

    public const RETAIL = 'retail';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'is_default_for_new' => 'boolean',
            'require_login_to_order' => 'boolean',
            'is_wholesale_default' => 'boolean',
            'sort_order' => 'integer',
            'visible_sections' => 'array',
            'category_access_mode' => CategoryAccessMode::class,
            'price_display' => PriceDisplayMode::class,
            'allowed_sales_modes' => 'array',
            'min_order_amount' => 'decimal:2',
            'allowed_delivery_codes' => 'array',
            'allowed_payment_codes' => 'array',
            'stock_display' => GroupStockDisplay::class,
            'default_discount_percent' => 'decimal:2',
            'show_retail_price' => 'boolean',
        ];
    }

    /**
     * Categories selected for the access mode (CG-05); subcategories are implied, not stored.
     *
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'customer_group_categories', 'customer_group_id', 'category_id');
    }

    /**
     * @param  Builder<CustomerGroup>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<CustomerGroup>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('sort_order'))->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Loads the `has_price_data` flag with the group row (no extra query, GP-08): whether the group has any tiers or
     * stored group prices. The storefront reads the viewer's group once per request and uses the flag to skip every price
     * query for a group that pays the retail price.
     *
     * @param  Builder<CustomerGroup>  $query
     */
    public function scopeWithPriceData(Builder $query): void
    {
        $table = $query->qualifyColumn('id');

        $query->select($query->qualifyColumn('*'))->selectRaw(
            '(exists (select 1 from product_group_prices_final gp where gp.customer_group_id = '.$table.') or exists (select 1 from product_price_tiers pt where pt.customer_group_id = '.$table.')) as has_price_data',
        );
    }

    /**
     * The `has_price_data` flag when the group was loaded with {@see scopeWithPriceData()}, otherwise null (unknown).
     */
    public function hasPriceDataFlag(): ?bool
    {
        return array_key_exists('has_price_data', $this->attributes) ? (bool) $this->attributes['has_price_data'] : null;
    }

    /**
     * Price rules bound to the group (GP-04).
     *
     * @return BelongsToMany<PriceRule, $this>
     */
    public function priceRules(): BelongsToMany
    {
        return $this->belongsToMany(PriceRule::class, 'price_rule_groups', 'customer_group_id', 'price_rule_id');
    }

    public function isGuest(): bool
    {
        return $this->code === self::GUEST;
    }

    public function allowsSection(StorefrontSection $section): bool
    {
        return in_array($section->value, $this->visible_sections, true);
    }

    /**
     * The «Гость» group; when the row is missing (never in a migrated database) a default one with the v1.0
     * behaviour, so the storefront keeps working.
     */
    public static function guest(): self
    {
        return self::query()->withPriceData()->where('code', self::GUEST)->first() ?? self::fallback(self::GUEST, 'Гость');
    }

    public static function retail(): self
    {
        return self::query()->withPriceData()->where('code', self::RETAIL)->first() ?? self::fallback(self::RETAIL, 'Розница');
    }

    /**
     * The group of a newly registered customer (CG-11): the active group flagged `is_default_for_new`, otherwise
     * «Розница».
     */
    public static function defaultForNew(): self
    {
        return self::query()->where('is_default_for_new', true)->where('is_active', true)->orderBy('id')->first() ?? self::retail();
    }

    /**
     * An unsaved group with the default (v1.0) behaviour.
     */
    private static function fallback(string $code, string $name): self
    {
        $group = new self;
        $group->forceFill([
            'code' => $code,
            'name' => $name,
            'is_system' => true,
            'is_active' => true,
            'sort_order' => 0,
            'visible_sections' => StorefrontSection::defaults(),
            'category_access_mode' => CategoryAccessMode::All,
            'price_display' => PriceDisplayMode::Show,
            'stock_display' => GroupStockDisplay::Inherit,
            'default_discount_percent' => '0.00',
            'show_retail_price' => false,
        ]);

        return $group;
    }
}
