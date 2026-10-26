<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\EmptyCellPolicy;
use App\Enums\ImportIssueLevel;
use App\Enums\PhotoMode;
use App\Enums\SalesMode;
use App\Exceptions\ImportRowException;
use App\Models\Category;
use App\Models\Import;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Applies the staged «Категории» sheet (`categories.jsonl`, IM-08) before the product rows: creates the path
 * (missing levels included — the sheet exists to define categories, so the "create categories" option does not
 * apply to it) and sets description, order, activity, sales mode and meta tags. Blank cells follow the
 * empty-cell policy. Photos are returned as tasks and processed after the commit ({@see ImportPhotoProcessor}).
 *
 * Issues of this sheet are stored with the column prefixed by «Лист «Категории»» so they never mix with the
 * issues of product rows that happen to have the same row number.
 */
final class CategorySheetImporter
{
    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    /**
     * Column prefix of every issue that belongs to the categories sheet.
     */
    public static function columnPrefix(): string
    {
        return __('import_process.categories_sheet');
    }

    /**
     * Process the whole staged sheet inside one transaction (rolled back for a dry run) and record the issues.
     */
    public function import(Import $import, CategoryPathResolver $resolver): CategorySheetResult
    {
        $options = $import->importOptions();
        $issues = new IssueBatch($import->id);
        $path = $import->categories_staging_path;
        $absolute = $path !== null ? Storage::disk('local')->path($path) : null;

        $created = 0;
        $updated = 0;
        $tasks = [];

        if ($absolute === null || ! is_file($absolute)) {
            return new CategorySheetResult(0, 0, 0, 0, []);
        }

        DB::beginTransaction();

        try {
            // Re-processing the sheet must not duplicate its issues.
            $this->deleteIssues($import);

            foreach ($this->lines($absolute) as $line) {
                $row = (int) ($line['row'] ?? 0);
                $errors = is_array($line['errors'] ?? null) ? $line['errors'] : [];

                if ($errors !== []) {
                    foreach ($errors as $error) {
                        $issues->addStaged($row, ImportIssueLevel::Error, (array) $error, self::columnPrefix());
                    }

                    continue;
                }

                try {
                    DB::beginTransaction();
                    $outcome = $this->applyRow($line, $resolver, $options->emptyCellPolicy, $options->photoMode);
                    DB::commit();
                } catch (ImportRowException $e) {
                    DB::rollBack();
                    $issues->add($row, ImportIssueLevel::Error, self::columnPrefix(), $e->getMessage());

                    continue;
                } catch (Throwable $e) {
                    DB::rollBack();

                    throw $e;
                }

                if ($outcome['created']) {
                    $created++;
                } else {
                    $updated++;
                }

                if ($outcome['photo'] !== null) {
                    $tasks[] = ['type' => 'category', 'id' => $outcome['id'], 'refs' => [$outcome['photo']], 'mode' => PhotoMode::Replace->value, 'row' => $row];
                }
            }

            if (! $options->dryRun) {
                $issues->flush();
            }

            $options->dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            app(CategoryTree::class)->flush();

            throw $e;
        }

        if ($options->dryRun) {
            // The transaction was rolled back; the tree cache may hold the phantom categories.
            app(CategoryTree::class)->flush();
            $issues->flush();
            $tasks = [];
        }

        return new CategorySheetResult($created, $updated, $issues->errorCount(), $issues->warningCount(), $tasks);
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{id: int, created: bool, photo: string|null}
     *
     * @throws ImportRowException
     */
    private function applyRow(array $line, CategoryPathResolver $resolver, EmptyCellPolicy $policy, PhotoMode $photoMode): array
    {
        /** @var list<string> $parts */
        $parts = array_values(array_map('strval', (array) ($line['path'] ?? [])));
        $before = $resolver->createdCount();
        $id = $resolver->resolve($parts, true);

        if ($id === null) {
            throw new ImportRowException(__('import.errors.required', ['field' => __('import.category_fields.path')]));
        }

        $category = Category::query()->findOrFail($id);
        $clear = $policy === EmptyCellPolicy::Clear;
        $changes = [];

        foreach (['description', 'meta_title', 'meta_description'] as $key) {
            if (! array_key_exists($key, $line)) {
                continue;
            }

            $value = is_string($line[$key]) && trim($line[$key]) !== '' ? trim($line[$key]) : null;

            if ($key === 'description' && $value !== null) {
                $value = $this->sanitizer->sanitize($value);
                $value = $value === '' ? null : $value;
            }

            if ($value !== null || $clear) {
                $changes[$key] = $value;
            }
        }

        if (isset($line['sort']) && is_int($line['sort'])) {
            $changes['sort_order'] = $line['sort'];
        }

        if (isset($line['active']) && is_bool($line['active'])) {
            $changes['is_active'] = $line['active'];
        }

        if (array_key_exists('sales_mode', $line)) {
            $mode = $line['sales_mode'];

            if (is_string($mode) && $mode !== '' && $mode !== ValueParser::SALES_MODE_DEFAULT) {
                $changes['sales_mode'] = SalesMode::tryFrom($mode);
            } elseif ($mode === ValueParser::SALES_MODE_DEFAULT || $clear) {
                $changes['sales_mode'] = null;
            }
        }

        if ($changes !== []) {
            $category->fill($changes)->save();
        }

        $photo = isset($line['photo']) && is_string($line['photo']) && trim($line['photo']) !== '' && $photoMode !== PhotoMode::Skip
            ? trim($line['photo'])
            : null;

        return ['id' => $id, 'created' => $resolver->createdCount() > $before, 'photo' => $photo];
    }

    private function deleteIssues(Import $import): void
    {
        $import->issues()->where('column', 'like', self::columnPrefix().'%')->delete();
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function lines(string $absolute): \Generator
    {
        $handle = fopen($absolute, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            while (($text = fgets($handle)) !== false) {
                $text = trim($text);

                if ($text === '') {
                    continue;
                }

                $decoded = json_decode($text, true);

                if (is_array($decoded)) {
                    yield $decoded;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
