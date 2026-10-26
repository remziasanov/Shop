<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain error of the category tree (AD-30, AD-32, AD-33). The message is already localized.
 */
final class CategoryTreeException extends RuntimeException
{
    public static function parentIsSelf(): self
    {
        return new self(__('catalog.errors.parent_is_self'));
    }

    public static function parentIsDescendant(): self
    {
        return new self(__('catalog.errors.parent_is_descendant'));
    }

    public static function tooDeep(int $maxDepth): self
    {
        return new self(__('catalog.errors.too_deep', ['max' => $maxDepth]));
    }

    public static function parentNotFound(): self
    {
        return new self(__('catalog.errors.parent_not_found'));
    }

    public static function notEmpty(): self
    {
        return new self(__('catalog.errors.category_not_empty'));
    }

    public static function cannotMoveProductsToRoot(): self
    {
        return new self(__('catalog.errors.cannot_move_products_to_root'));
    }
}
