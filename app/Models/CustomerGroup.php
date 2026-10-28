<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoryAccessMode;
use App\Enums\GroupStockDisplay;
use App\Enums\PriceDisplayMode;
use App\Enums\StorefrontSection;
use App\Observers\CustomerGroupObserver;
use App\Services\Access\CatalogAccess;
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
 */
#[ObservedBy(CustomerGroupObserver::class)]
#[Fillable([
    'code', 'name', 'description', 'is_active', 'sort_order', 'visible_sections', 'category_access_mode',
    'price_display', 'allowed_sales_modes', 'min_order_amount', 'allowed_delivery_codes', 'allowed_payment_codes',
    'stock_display',
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
            'sort_order' => 'integer',
            'visible_sections' => 'array',
            'category_access_mode' => CategoryAccessMode::class,
            'price_display' => PriceDisplayMode::class,
            'allowed_sales_modes' => 'array',
            'min_order_amount' => 'decimal:2',
            'allowed_delivery_codes' => 'array',
            'allowed_payment_codes' => 'array',
            'stock_display' => GroupStockDisplay::class,
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
        return self::query()->where('code', self::GUEST)->first() ?? self::fallback(self::GUEST, 'Гость');
    }

    public static function retail(): self
    {
        return self::query()->where('code', self::RETAIL)->first() ?? self::fallback(self::RETAIL, 'Розница');
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
        ]);

        return $group;
    }
}
