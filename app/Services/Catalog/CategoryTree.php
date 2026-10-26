<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Exceptions\CategoryTreeException;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;

/**
 * Category tree: materialized path / depth maintenance, validation (AD-30, AD-32), the cached tree
 * (NF-04) and everything derived from it (visibility, product counts, breadcrumbs, select options).
 *
 * Register as a singleton. The tree is cached forever under {@see CACHE_KEY} and flushed by the
 * Category observer on every save/delete; call {@see flush()} after any raw change to `categories`.
 * Reads from the cached tree never query the database.
 */
final class CategoryTree
{
    public const CACHE_KEY = 'catalog.tree';

    /** @var array<int, CategoryNode>|null Per-request index of the cached tree, by id. */
    private ?array $nodes = null;

    /** @var list<CategoryNode> */
    private array $roots = [];

    /** @var list<int>|null */
    private ?array $visibleIds = null;

    public function __construct(private readonly CacheRepository $cache) {}

    // ------------------------------------------------------------------
    // Cached tree
    // ------------------------------------------------------------------

    /**
     * Root nodes in manual order; each node has its `children`.
     *
     * @return list<CategoryNode>
     */
    public function roots(): array
    {
        $this->load();

        return $this->roots;
    }

    /**
     * All nodes indexed by id (flat).
     *
     * @return array<int, CategoryNode>
     */
    public function nodes(): array
    {
        $this->load();

        return $this->nodes ?? [];
    }

    public function node(int $id): ?CategoryNode
    {
        return $this->nodes()[$id] ?? null;
    }

    /**
     * Drop the cached tree. Called by the observer; the next read rebuilds it.
     */
    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->forgetMemo();
    }

    /**
     * Drop only the in-memory copy, so the next read goes to the cache again. Long-running queue
     * workers call this before each job, otherwise they would keep a stale tree.
     */
    public function forgetMemo(): void
    {
        $this->nodes = null;
        $this->roots = [];
        $this->visibleIds = null;
    }

    /**
     * Id of the category and of all its descendants (the category itself first).
     *
     * @return list<int>
     */
    public function descendantIds(int $id): array
    {
        $node = $this->node($id);

        if ($node === null) {
            return [$id];
        }

        $ids = [];
        $this->collectIds($node, $ids);

        return $ids;
    }

    /**
     * Id of the category followed by the ids of all its ancestors, nearest first. Unknown ids yield just [$id].
     *
     * @return list<int>
     */
    public function selfAndAncestorIds(int $id): array
    {
        $ids = [];

        foreach (array_reverse($this->breadcrumbs($id)) as $node) {
            $ids[] = $node->id;
        }

        return $ids === [] ? [$id] : $ids;
    }

    /**
     * Ids of categories shown on the storefront: active, with every ancestor active too (SF-16).
     *
     * @return list<int>
     */
    public function visibleCategoryIds(): array
    {
        if ($this->visibleIds !== null) {
            return $this->visibleIds;
        }

        $ids = [];
        $walk = function (CategoryNode $node) use (&$walk, &$ids): void {
            if (! $node->isActive) {
                return;
            }
            $ids[] = $node->id;
            foreach ($node->children as $child) {
                $walk($child);
            }
        };

        foreach ($this->roots() as $root) {
            $walk($root);
        }

        return $this->visibleIds = $ids;
    }

    public function isVisible(int $id): bool
    {
        return in_array($id, $this->visibleCategoryIds(), true);
    }

    /**
     * Ancestors of a category from the root down, without the category itself.
     *
     * @return list<CategoryNode>
     */
    public function ancestors(Category|CategoryNode|int $category): array
    {
        $nodes = $this->breadcrumbs($category);
        array_pop($nodes);

        return $nodes;
    }

    /**
     * Breadcrumb chain from the root down to (and including) the category.
     *
     * @return list<CategoryNode>
     */
    public function breadcrumbs(Category|CategoryNode|int $category): array
    {
        $id = is_int($category) ? $category : $category->id;
        $node = $this->node($id);

        if ($node === null) {
            return [];
        }

        $chain = [];
        foreach (explode('/', $node->path) as $segment) {
            if ($segment !== '' && ($ancestor = $this->node((int) $segment)) !== null) {
                $chain[] = $ancestor;
            }
        }

        return $chain;
    }

    /**
     * Products per category including all descendants (D-021): one grouped query, summed in PHP.
     * Soft-deleted products are not counted.
     *
     * With $storefront = true only active products in visible categories are counted (SF-16),
     * and invisible categories get 0.
     *
     * @return array<int, int> category id => number of products
     */
    public function productCountsWithDescendants(bool $storefront = false): array
    {
        $direct = Product::query()
            ->when($storefront, fn ($query) => $query->where('is_active', true))
            ->selectRaw('category_id, count(*) as aggregate')
            ->groupBy('category_id')
            ->pluck('aggregate', 'category_id')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $visible = $storefront ? array_flip($this->visibleCategoryIds()) : null;
        $totals = [];

        $sum = function (CategoryNode $node) use (&$sum, &$totals, $direct, $visible): int {
            $isCounted = $visible === null || isset($visible[$node->id]);
            $total = $isCounted ? ($direct[$node->id] ?? 0) : 0;

            foreach ($node->children as $child) {
                $total += $sum($child);
            }

            return $totals[$node->id] = $total;
        };

        foreach ($this->roots() as $root) {
            $sum($root);
        }

        return $totals;
    }

    /**
     * Select options "Родитель / Дочерняя" in tree order.
     *
     * @param  int|null  $excludeSubtreeOf  leave out this category and its descendants
     * @return array<int, string> id => label
     */
    public function options(?int $excludeSubtreeOf = null): array
    {
        return $this->buildOptions($excludeSubtreeOf, null, false);
    }

    /**
     * Select options with a visual indent ("— " per level) instead of the full path.
     *
     * @return array<int, string> id => label
     */
    public function indentedOptions(?int $excludeSubtreeOf = null): array
    {
        return $this->buildOptions($excludeSubtreeOf, null, true);
    }

    /**
     * Valid parents for a category form (AD-32, AD-30): never the category itself or its descendants,
     * and only parents that keep the deepest descendant within {@see Category::MAX_DEPTH}.
     *
     * @return array<int, string> id => "Родитель / Дочерняя"
     */
    public function parentOptions(?Category $editing = null): array
    {
        if ($editing === null || ! $editing->exists) {
            return $this->buildOptions(null, Category::MAX_DEPTH - 1, false);
        }

        $node = $this->node($editing->id);
        $height = $node !== null ? $this->subtreeHeight($node) : 1;

        return $this->buildOptions($editing->id, Category::MAX_DEPTH - $height, false);
    }

    /**
     * Persist a manual order among siblings of one parent (drag and drop, AD-30).
     *
     * @param  list<int>  $orderedIds  ids of the children of $parentId in the desired order
     */
    public function reorder(?int $parentId, array $orderedIds): void
    {
        DB::transaction(function () use ($parentId, $orderedIds): void {
            foreach ($orderedIds as $position => $id) {
                Category::query()
                    ->whereKey($id)
                    ->when(
                        $parentId === null,
                        fn ($query) => $query->whereNull('parent_id'),
                        fn ($query) => $query->where('parent_id', $parentId),
                    )
                    ->update(['sort_order' => $position]);
            }
        });

        $this->flush();
    }

    // ------------------------------------------------------------------
    // Hierarchy maintenance (used by the observer)
    // ------------------------------------------------------------------

    /**
     * Validate the parent of a category about to be saved and set its `depth` (and `path` for existing
     * categories). New categories get their `path` in {@see finalizeCreated()}, once the id is known.
     *
     * @throws CategoryTreeException
     */
    public function prepareForSave(Category $category): void
    {
        $parent = $category->parent_id !== null ? $this->findParent($category) : null;

        $height = 1;

        if ($category->exists) {
            /** @var Category|null $current */
            $current = Category::query()->find($category->id, ['id', 'depth', 'path']);

            if ($current !== null && $current->path !== '') {
                if ($parent !== null && str_starts_with($parent->path, $current->path)) {
                    throw CategoryTreeException::parentIsDescendant();
                }

                $deepest = (int) Category::query()->where('path', 'like', $current->path.'%')->max('depth');
                $height = max(1, $deepest - $current->depth + 1);
            }
        }

        $depth = ($parent !== null ? $parent->depth : 0) + 1;

        if ($depth + $height - 1 > Category::MAX_DEPTH) {
            throw CategoryTreeException::tooDeep(Category::MAX_DEPTH);
        }

        $category->depth = $depth;

        if ($category->exists) {
            $category->path = ($parent !== null ? $parent->path : '').$category->id.'/';
        }
    }

    /**
     * @throws CategoryTreeException
     */
    private function findParent(Category $category): Category
    {
        if ($category->exists && $category->parent_id === $category->id) {
            throw CategoryTreeException::parentIsSelf();
        }

        return Category::query()->find($category->parent_id, ['id', 'depth', 'path'])
            ?? throw CategoryTreeException::parentNotFound();
    }

    /**
     * Set the materialized path of a just-inserted category.
     */
    public function finalizeCreated(Category $category): void
    {
        $parentPath = $category->parent_id !== null
            ? (string) Category::query()->whereKey($category->parent_id)->value('path')
            : '';

        $path = $parentPath.$category->id.'/';

        Category::query()->whereKey($category->id)->update(['path' => $path]);

        $category->path = $path;
        $category->syncOriginalAttribute('path');
    }

    /**
     * After the parent changed, recompute path and depth of all descendants (one transaction).
     */
    public function cascadeToDescendants(Category $category): void
    {
        $oldPath = (string) $category->getOriginal('path');
        $oldDepth = (int) $category->getOriginal('depth');

        if ($oldPath === '' || ($oldPath === $category->path && $oldDepth === $category->depth)) {
            return;
        }

        $depthDelta = $category->depth - $oldDepth;

        DB::transaction(function () use ($category, $oldPath, $depthDelta): void {
            $descendants = Category::query()
                ->where('path', 'like', $oldPath.'%')
                ->where('id', '!=', $category->id)
                ->get(['id', 'path', 'depth']);

            foreach ($descendants as $descendant) {
                Category::query()->whereKey($descendant->id)->update([
                    'path' => $category->path.substr($descendant->path, strlen($oldPath)),
                    'depth' => $descendant->depth + $depthDelta,
                ]);
            }
        });
    }

    /**
     * Refuse to delete a category that still has subcategories or products (including soft-deleted).
     *
     * @throws CategoryTreeException
     */
    public function assertEmpty(Category $category): void
    {
        $hasChildren = Category::query()->where('parent_id', $category->id)->exists();
        $hasProducts = Product::withTrashed()->where('category_id', $category->id)->exists();

        if ($hasChildren || $hasProducts) {
            throw CategoryTreeException::notEmpty();
        }
    }

    /**
     * Delete a category after moving its subcategories and products to its parent (AD-33).
     * A root category that holds products cannot be deleted this way (nowhere to move them);
     * its subcategories become roots.
     *
     * @throws CategoryTreeException
     */
    public function deleteMovingToParent(Category $category): void
    {
        $parentId = $category->parent_id;

        $hasProducts = Product::withTrashed()->where('category_id', $category->id)->exists();

        if ($parentId === null && $hasProducts) {
            throw CategoryTreeException::cannotMoveProductsToRoot();
        }

        DB::transaction(function () use ($category, $parentId, $hasProducts): void {
            foreach (Category::query()->where('parent_id', $category->id)->get() as $child) {
                $child->parent_id = $parentId;
                $child->save();
            }

            if ($hasProducts) {
                Product::withTrashed()->where('category_id', $category->id)->update(['category_id' => $parentId]);
            }

            $category->delete();
        });

        $this->flush();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function load(): void
    {
        if ($this->nodes !== null) {
            return;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            return Category::query()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'parent_id', 'name', 'slug', 'is_active', 'sales_mode', 'sort_order', 'depth', 'path', 'image_path'])
                ->map(fn (Category $category): array => $category->getAttributes())
                ->all();
        });

        $nodes = [];
        foreach ($rows as $row) {
            $node = CategoryNode::fromRow($row);
            $nodes[$node->id] = $node;
        }

        $roots = [];
        foreach ($nodes as $node) {
            $parent = $node->parentId !== null ? ($nodes[$node->parentId] ?? null) : null;

            if ($parent !== null) {
                $parent->children[] = $node;
            } else {
                $roots[] = $node;
            }
        }

        $this->nodes = $nodes;
        $this->roots = $roots;
    }

    /**
     * @param  list<int>  $ids
     */
    private function collectIds(CategoryNode $node, array &$ids): void
    {
        $ids[] = $node->id;

        foreach ($node->children as $child) {
            $this->collectIds($child, $ids);
        }
    }

    /**
     * Number of levels in the subtree, the node itself included.
     */
    private function subtreeHeight(CategoryNode $node): int
    {
        $max = 0;

        foreach ($node->children as $child) {
            $max = max($max, $this->subtreeHeight($child));
        }

        return $max + 1;
    }

    /**
     * @return array<int, string>
     */
    private function buildOptions(?int $excludeSubtreeOf, ?int $maxDepth, bool $indent): array
    {
        $options = [];
        $separator = __('catalog.categories.path_separator');

        $walk = function (CategoryNode $node, string $prefix) use (&$walk, &$options, $excludeSubtreeOf, $maxDepth, $indent, $separator): void {
            if ($node->id === $excludeSubtreeOf) {
                return;
            }

            if ($maxDepth === null || $node->depth <= $maxDepth) {
                $options[$node->id] = $indent
                    ? str_repeat('— ', $node->depth - 1).$node->name
                    : $prefix.$node->name;
            }

            foreach ($node->children as $child) {
                $walk($child, $prefix.$node->name.$separator);
            }
        };

        foreach ($this->roots() as $root) {
            $walk($root, '');
        }

        return $options;
    }
}
