<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * An active characteristic filter as a removable chip (FL-13): the label and the URL without it.
 */
final readonly class FilterChip
{
    public function __construct(
        public string $label,
        public string $url,
        public string $removeLabel,
    ) {}
}
