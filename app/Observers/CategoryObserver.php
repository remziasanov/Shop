<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Category;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\RedirectService;
use App\Services\Catalog\SlugService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Thin glue: slug, hierarchy, redirects and cache flushing are implemented in the services.
 */
final class CategoryObserver
{
    public function __construct(
        private readonly SlugService $slugs,
        private readonly CategoryTree $tree,
        private readonly RedirectService $redirects,
    ) {}

    public function saving(Category $category): void
    {
        if (! $category->exists || $category->isDirty('slug') || trim((string) $category->slug) === '') {
            $category->slug = $this->slugs->resolve('categories', $category->slug, (string) $category->name, $category->id);
        }

        if (! $category->exists || $category->isDirty('parent_id')) {
            $this->tree->prepareForSave($category);
        }
    }

    public function created(Category $category): void
    {
        $this->tree->finalizeCreated($category);
    }

    public function updated(Category $category): void
    {
        if ($category->wasChanged('parent_id')) {
            $this->tree->cascadeToDescendants($category);
        }

        // The replaced (or removed) image file is not kept.
        if ($category->wasChanged('image_path')) {
            $old = $category->getOriginal('image_path');

            if (is_string($old) && $old !== '') {
                // After commit: a rolled-back save must keep pointing at an existing file.
                DB::afterCommit(fn () => Storage::disk('public')->delete($old));
            }
        }

        if ($category->wasChanged('slug')) {
            $this->redirects->record('/catalog/'.$category->getOriginal('slug'), '/catalog/'.$category->slug);
        }
    }

    public function saved(Category $category): void
    {
        $this->flush();
    }

    public function deleting(Category $category): void
    {
        $this->tree->assertEmpty($category);
    }

    public function deleted(Category $category): void
    {
        if ($category->image_path) {
            Storage::disk('public')->delete($category->image_path);
        }

        $this->flush();
    }

    /**
     * Flush now and again after the surrounding transaction commits, so a concurrent request cannot
     * re-cache the pre-commit state.
     */
    private function flush(): void
    {
        $this->tree->flush();
        DB::afterCommit(fn () => $this->tree->flush());
    }
}
