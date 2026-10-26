<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Pages\Concerns;

use App\Exceptions\CategoryTreeException;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Turns a category tree error raised on save (AD-32) into a validation error on the parent field.
 */
trait MapsTreeErrors
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    protected function guardTree(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (CategoryTreeException $e) {
            throw ValidationException::withMessages(['data.parent_id' => $e->getMessage()]);
        }
    }
}
