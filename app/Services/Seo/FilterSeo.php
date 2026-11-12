<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * SEO of a category page that has characteristic filters (FL-14 «б» / «в»).
 */
final readonly class FilterSeo
{
    public function __construct(
        public string $canonical,
        public ?string $robots,
        public string $filtersText,
        public string $heading,
        public bool $indexable,
    ) {}
}
