<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Exceptions\CategoryTreeException;
use App\Exceptions\ImportRowException;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * Finds (and creates) categories by a path of names (IM-08): "Электроника / Телефоны / Смартфоны".
 *
 * A level is matched by name inside its parent, case-insensitively and with «ё» = «е». Missing levels are created
 * when allowed (through the Category model, so slugs, depth, path and cache flushing come from the observer).
 * The whole table is read once into a memo (id, parent, name) that is kept up to date on creation, so a run of
 * thousands of rows does not query categories per row. Create one instance per processed chunk: the memo must not
 * outlive a transaction that may be rolled back (dry run).
 */
final class CategoryPathResolver
{
    /** @var array<string, int>|null "parentId|normalized name" => id (parent 0 = root) */
    private ?array $index = null;

    private int $created = 0;

    /**
     * @param  list<string>  $path  names from the root down
     * @param  bool  $create  create missing levels; otherwise return null when any level is missing
     * @return int|null id of the last level; null for an empty path or (without $create) a missing level
     *
     * @throws ImportRowException the path is deeper than the tree allows
     */
    public function resolve(array $path, bool $create): ?int
    {
        $path = array_values(array_filter(array_map(fn (string $name): string => trim($name), $path), fn (string $name): bool => $name !== ''));

        if ($path === []) {
            return null;
        }

        if (count($path) > Category::MAX_DEPTH) {
            throw new ImportRowException(__('import_process.errors.category_too_deep', ['max' => Category::MAX_DEPTH]));
        }

        $this->load();

        $parentId = 0;

        foreach ($path as $name) {
            $key = $parentId.'|'.self::normalize($name);
            $id = $this->index[$key] ?? null;

            if ($id === null) {
                if (! $create) {
                    return null;
                }

                $id = $this->create($name, $parentId === 0 ? null : $parentId);
                $this->index[$key] = $id;
            }

            $parentId = $id;
        }

        return $parentId;
    }

    /**
     * Number of categories created through this instance.
     */
    public function createdCount(): int
    {
        return $this->created;
    }

    /**
     * Lower case, «ё» => «е», single spaces: the key under which names are compared.
     */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = str_replace('ё', 'е', $name);

        return (string) preg_replace('/\s+/u', ' ', $name);
    }

    private function create(string $name, ?int $parentId): int
    {
        try {
            $category = Category::query()->create([
                'parent_id' => $parentId,
                'name' => $name,
                'is_active' => true,
            ]);
        } catch (CategoryTreeException $e) {
            throw new ImportRowException($e->getMessage(), 0, $e);
        }

        $this->created++;

        return $category->id;
    }

    private function load(): void
    {
        if ($this->index !== null) {
            return;
        }

        $this->index = [];

        foreach (DB::table('categories')->orderBy('id')->get(['id', 'parent_id', 'name']) as $row) {
            $this->index[((int) $row->parent_id).'|'.self::normalize((string) $row->name)] ??= (int) $row->id;
        }
    }
}
