<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Redirect;
use Illuminate\Support\Facades\DB;

/**
 * 301 redirects for changed slugs (SF-80, D-018).
 *
 * Invariants kept by {@see record()}: no chains (every `to_path` is a live path, never another
 * `from_path`) and no loops (a path that is live again stops being a redirect source).
 * Lookups happen only on a 404 on the storefront, so ordinary requests never touch this table.
 */
final class RedirectService
{
    /**
     * Register that content moved from $oldPath to $newPath.
     */
    public function record(string $oldPath, string $newPath): void
    {
        $old = $this->normalizePath($oldPath);
        $new = $this->normalizePath($newPath);

        if ($old === $new) {
            return;
        }

        DB::transaction(function () use ($old, $new): void {
            // The new path is live again: it must not be a redirect source (loop).
            Redirect::query()->where('from_path', $new)->delete();

            // Everything that pointed to the old path now points to the new one (no chains).
            Redirect::query()->where('to_path', $old)->update(['to_path' => $new]);

            Redirect::query()->updateOrCreate(['from_path' => $old], ['to_path' => $new]);
        });
    }

    /**
     * Target path for a requested path, or null if there is no redirect.
     */
    public function find(string $path): ?string
    {
        $value = Redirect::query()->where('from_path', $this->normalizePath($path))->value('to_path');

        return is_string($value) ? $value : null;
    }

    /**
     * Drop redirects that point to a path which no longer exists (e.g. a force-deleted product).
     */
    public function forgetTarget(string $path): void
    {
        Redirect::query()->where('to_path', $this->normalizePath($path))->delete();
    }

    /**
     * Leading slash, no trailing slash, no query string.
     */
    public function normalizePath(string $path): string
    {
        $path = explode('?', $path, 2)[0];

        return '/'.trim($path, '/');
    }
}
