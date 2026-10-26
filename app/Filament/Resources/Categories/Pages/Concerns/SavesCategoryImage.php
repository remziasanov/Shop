<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages\Concerns;

use App\Exceptions\CategoryTreeException;
use App\Exceptions\ImageProcessingException;
use App\Models\Category;
use App\Services\Media\CategoryImageImporter;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Saves a category together with its uploaded image: the file waits in a private temporary directory
 * and is processed once the category exists (AD-31). A processing error becomes a validation error on
 * the upload field and the category is not saved.
 */
trait SavesCategoryImage
{
    /**
     * @param  array<string, mixed>  $data  form data including the virtual `new_image` / `remove_image` fields
     * @param  Closure(array<string, mixed>): Model  $save  persists the category from the cleaned data
     *
     * @throws ValidationException
     */
    protected function saveWithImage(array $data, Closure $save): Model
    {
        $upload = $data['new_image'] ?? null;
        $temporaryPath = is_array($upload) ? (reset($upload) ?: null) : $upload;
        $remove = (bool) ($data['remove_image'] ?? false);
        unset($data['new_image'], $data['remove_image']);

        try {
            return DB::transaction(function () use ($data, $save, $temporaryPath, $remove): Model {
                $record = $save($data);

                if ($record instanceof Category) {
                    app(CategoryImageImporter::class)->apply($record, is_string($temporaryPath) ? $temporaryPath : null, $remove);
                }

                return $record;
            });
        } catch (ImageProcessingException $e) {
            throw ValidationException::withMessages(['data.new_image' => $e->getMessage()]);
        } catch (ValidationException|CategoryTreeException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($temporaryPath !== null) {
                report($e);

                throw ValidationException::withMessages(['data.new_image' => ImageProcessingException::corrupt()->getMessage()]);
            }

            throw $e;
        }
    }
}
