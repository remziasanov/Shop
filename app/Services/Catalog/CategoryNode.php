<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\SalesMode;
use App\Models\Category;

/**
 * Lightweight category from the cached tree ({@see CategoryTree}); no Eloquent, no queries.
 */
final class CategoryNode
{
    /** @var list<CategoryNode> Direct children in manual order. */
    public array $children = [];

    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly string $name,
        public readonly string $slug,
        public readonly bool $isActive,
        public readonly ?string $salesMode,
        public readonly int $sortOrder,
        public readonly int $depth,
        public readonly string $path,
        public readonly ?string $imagePath = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            parentId: $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            isActive: (bool) $row['is_active'],
            salesMode: $row['sales_mode'] !== null ? (string) $row['sales_mode'] : null,
            sortOrder: (int) $row['sort_order'],
            depth: (int) $row['depth'],
            path: (string) $row['path'],
            imagePath: $row['image_path'] !== null ? (string) $row['image_path'] : null,
        );
    }

    public function salesModeEnum(): ?SalesMode
    {
        return $this->salesMode !== null ? SalesMode::tryFrom($this->salesMode) : null;
    }

    /**
     * Hydrate a (partial, non-dirty) Category model from the cached data, without a query.
     * Fields not kept in the cache (description, meta) are not loaded.
     */
    public function toModel(): Category
    {
        return (new Category)->newFromBuilder([
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'name' => $this->name,
            'slug' => $this->slug,
            'is_active' => $this->isActive ? 1 : 0,
            'sales_mode' => $this->salesMode,
            'sort_order' => $this->sortOrder,
            'depth' => $this->depth,
            'path' => $this->path,
            'image_path' => $this->imagePath,
        ]);
    }
}
