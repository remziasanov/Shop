<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SalesMode;
use App\Observers\CategoryObserver;
use App\Services\Catalog\CategoryTree;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catalog category (tree, max depth 4, materialized path).
 *
 * `path` is a string of ids "1/5/12/" whose last segment is the category itself; `depth` of a root is 1.
 * Both are maintained by {@see CategoryTree} through the observer, never set by hand.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $image_path
 * @property bool $is_active
 * @property SalesMode|null $sales_mode
 * @property int $sort_order
 * @property int $depth
 * @property string $path
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property-read Category|null $parent
 * @property-read Collection<int, Category> $children
 * @property-read Collection<int, Product> $products
 */
#[ObservedBy(CategoryObserver::class)]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    public const MAX_DEPTH = 4;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'image_path',
        'is_active',
        'sales_mode',
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
            'is_active' => 'boolean',
            'sales_mode' => SalesMode::class,
            'sort_order' => 'integer',
            'depth' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * Direct children in manual order.
     *
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Products placed directly in this category (not in subcategories).
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Only categories flagged active. Use {@see scopeVisible()} for storefront visibility (SF-16).
     *
     * @param  Builder<Category>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Active categories whose ancestors are all active as well.
     *
     * @param  Builder<Category>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->whereIn($query->qualifyColumn('id'), app(CategoryTree::class)->visibleCategoryIds());
    }

    /**
     * Ids from the materialized path, root first, including the category itself.
     *
     * @return list<int>
     */
    public function pathIds(): array
    {
        return array_values(array_map('intval', array_filter(explode('/', $this->path), fn (string $s): bool => $s !== '')));
    }

    /**
     * Ancestors (root first, without the category itself) loaded through the materialized path.
     *
     * @return Collection<int, Category>
     */
    public function ancestors(): Collection
    {
        $ids = array_values(array_filter($this->pathIds(), fn (int $id): bool => $id !== $this->id));

        if ($ids === []) {
            return new Collection;
        }

        return self::query()->whereIn('id', $ids)->orderBy('depth')->get();
    }

    /**
     * Query of all descendants (children, grandchildren, …), without the category itself.
     *
     * @return Builder<Category>
     */
    public function descendants(): Builder
    {
        return self::query()
            ->where('path', 'like', $this->path.'%')
            ->where('id', '!=', $this->id);
    }
}
