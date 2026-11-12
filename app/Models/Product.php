<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgeRestriction;
use App\Enums\SalesMode;
use App\Observers\ProductObserver;
use App\Services\Access\ViewerContext;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use App\Services\Search\SearchNormalizer;
use App\Services\Search\VariantSearch;
use App\Services\Stock\Stockable;
use App\Support\Money;
use Carbon\CarbonInterface;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Catalog product.
 *
 * Money columns are decimal(12,2) and are cast to strings ("1290.00"); do arithmetic through
 * {@see Money} (kopecks), never floats. `final_price`, `display_old_price`, `price_calculated_at`,
 * `slug` (when empty) and `search_text` are maintained by the observer and not mass assignable.
 *
 * Note: the TZ column is named `attributes` (list of {name, value}). Eloquent keeps its own raw
 * `$attributes` property, so access it as `$product->getAttribute('attributes')` or via {@see specs()}.
 *
 * @property int $id
 * @property int $category_id
 * @property string $sku
 * @property string $name
 * @property string $slug
 * @property string|null $brand
 * @property string|null $short_description
 * @property string|null $description
 * @property string $base_price
 * @property string|null $cost_price
 * @property string|null $compare_at_price
 * @property string|null $fixed_price
 * @property bool $price_on_request
 * @property string $final_price with variants: the minimum over the active variants (VR-07)
 * @property string|null $final_price_max with variants: the maximum over the active variants; null without variants
 * @property bool $has_variants the card works with variants (VR-02); toggled by VariantService only
 * @property string|null $display_old_price
 * @property CarbonInterface|null $price_calculated_at
 * @property int|null $stock_qty with variants: an aggregate over the active variants (VR-06)
 * @property int $reserved_qty with variants: an aggregate over the active variants (VR-06)
 * @property string $unit
 * @property SalesMode|null $sales_mode
 * @property AgeRestriction|null $age_restriction own level only; the effective one comes from AgeRestrictionResolver
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $is_new
 * @property string $search_text
 * @property int $sort_order
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property-read Category $category
 * @property-read Collection<int, ProductImage> $images
 * @property-read ProductImage|null $mainImage
 * @property-read Collection<int, ProductAttributeValue> $attributeValues
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, OptionType> $options axes of the card in order
 */
#[ObservedBy(ProductObserver::class)]
class Product extends Model implements Stockable
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /** New products work without variants (VR-02); written through VariantService. */
    protected $attributes = ['has_variants' => false];

    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'slug',
        'brand',
        'short_description',
        'description',
        'attributes',
        'base_price',
        'cost_price',
        'compare_at_price',
        'fixed_price',
        'price_on_request',
        'stock_qty',
        'unit',
        'sales_mode',
        'age_restriction',
        'is_active',
        'is_featured',
        'is_new',
        'sort_order',
        'meta_title',
        'meta_description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'base_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'fixed_price' => 'decimal:2',
            'final_price' => 'decimal:2',
            'final_price_max' => 'decimal:2',
            'has_variants' => 'boolean',
            'display_old_price' => 'decimal:2',
            'price_on_request' => 'boolean',
            'price_calculated_at' => 'datetime',
            'stock_qty' => 'integer',
            'reserved_qty' => 'integer',
            'sales_mode' => SalesMode::class,
            'age_restriction' => AgeRestriction::class,
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'sort_order' => 'integer',
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
     * All photos in display order (first = main).
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The main photo (lowest sort_order); eager-loadable without loading every image.
     *
     * @return HasOne<ProductImage, $this>
     */
    public function mainImage(): HasOne
    {
        return $this->hasOne(ProductImage::class)->ofMany(['sort_order' => 'min', 'id' => 'min']);
    }

    /**
     * Normalized characteristic values (FL-03); written only through AttributeService.
     *
     * @return HasMany<ProductAttributeValue, $this>
     */
    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    /**
     * Live variants in display order (VR-03).
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Axes of the card: option types in axis order (VR-02).
     *
     * @return BelongsToMany<OptionType, $this>
     */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(OptionType::class, 'product_options')
            ->withPivot('sort_order')
            ->orderBy('product_options.sort_order');
    }

    /**
     * Products visible on the storefront: the product is active and so is its category together with
     * all ancestors (SF-16). Uses the cached category tree, no extra query.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true)
            ->whereIn($query->qualifyColumn('category_id'), app(CategoryTree::class)->visibleCategoryIds());
    }

    /**
     * Products the current storefront viewer may see: active ones in the categories of the viewer's customer group
     * (CG-13). Use it for every storefront query; admin and console code uses {@see scopeActive()} or no scope.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeStorefront(Builder $query): void
    {
        $query->active();
        app(ViewerContext::class)->access()->applyToProducts($query, $query->qualifyColumn('category_id'));
    }

    /**
     * Search by name / SKU / brand (D-019): every word must occur in `search_text`; an exact SKU match
     * is ordered first. The exact article or barcode of an active variant matches too and comes before all (VR-21). Adds an ORDER BY, so apply it before other orderings when relevance matters.
     *
     * @param  Builder<Product>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $normalizer = app(SearchNormalizer::class);
        $variants = app(VariantSearch::class);
        $words = $normalizer->words($term);

        if ($words === []) {
            return;
        }

        $column = $query->qualifyColumn('search_text');
        $idColumn = $query->qualifyColumn('id');
        $exact = $variants->exactTerm($term);

        // VR-21: all words match the card's search text, OR the phrase is exactly the article / barcode of an active variant.
        $query->where(function (Builder $match) use ($words, $normalizer, $column, $variants, $idColumn, $exact): void {
            $match->where(function (Builder $like) use ($words, $normalizer, $column): void {
                foreach ($words as $word) {
                    $like->whereRaw("{$column} like ? escape '!'", ['%'.$normalizer->escapeLike($word).'%']);
                }
            });
            $match->orWhereExists(fn (QueryBuilder $exists) => $variants->constrain($exists, $idColumn, $exact));
        });

        // Cards found by a variant article come first, then those found by the card's own article.
        $query->orderByRaw(
            "case when exists (select 1 from product_variants pv where pv.product_id = {$idColumn} and pv.is_active = 1 and pv.deleted_at is null and (pv.sku = ? or pv.barcode = ?)) then 0 else 1 end",
            [$exact, $exact],
        );
        $query->orderByRaw("case when lower({$query->qualifyColumn('sku')}) = ? then 0 else 1 end", [$normalizer->normalize($term)]);
    }

    /**
     * Characteristics as a clean list of ['name' => string, 'value' => string] pairs.
     *
     * @return list<array{name: string, value: string}>
     */
    public function specs(): array
    {
        $raw = $this->getAttribute('attributes');
        $specs = [];

        if (! is_array($raw)) {
            return $specs;
        }

        foreach ($raw as $row) {
            if (is_array($row) && isset($row['name']) && (string) $row['name'] !== '') {
                $specs[] = ['name' => (string) $row['name'], 'value' => (string) ($row['value'] ?? '')];
            }
        }

        return $specs;
    }

    /**
     * Stock that can still be sold: stock_qty - reserved_qty, or null when stock is not tracked.
     * For a card with variants this is the aggregate; decisions about availability use the variant.
     */
    public function availableQty(): ?int
    {
        return $this->stock_qty === null ? null : $this->stock_qty - $this->reserved_qty;
    }

    public function stockProduct(): Product
    {
        return $this;
    }

    public function stockProductId(): int
    {
        return $this->id;
    }

    public function stockVariantId(): ?int
    {
        return null;
    }

    public function stockName(): string
    {
        return (string) $this->name;
    }

    /**
     * Discount in whole percent when an old price is shown, otherwise null.
     */
    public function discountPercent(): ?int
    {
        if ($this->display_old_price === null) {
            return null;
        }

        $old = Money::fromRubles($this->display_old_price);
        $final = Money::fromRubles($this->final_price);

        if ($old <= 0 || $final >= $old) {
            return null;
        }

        return (int) round(($old - $final) * 100 / $old);
    }

    /**
     * Full description sanitized for output (NF-10).
     */
    public function safeDescription(): string
    {
        return app(HtmlSanitizer::class)->sanitize($this->description);
    }
}
