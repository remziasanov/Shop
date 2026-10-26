<?php

declare(strict_types=1);

namespace App\Services\Seo;

/**
 * Everything the layout needs for the <head> of one page (SF-81, SF-83).
 */
final readonly class SeoMeta
{
    /**
     * @param  list<array<string, mixed>>  $jsonLd  schema.org objects, each rendered as its own ld+json script
     */
    public function __construct(
        public string $title,
        public string $description = '',
        public ?string $canonical = null,
        public ?string $robots = null,
        public string $ogType = 'website',
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?string $ogImage = null,
        public ?string $ogUrl = null,
        public array $jsonLd = [],
    ) {}

    public function withoutCanonical(): self
    {
        return new self($this->title, $this->description, null, $this->robots, $this->ogType, $this->ogTitle, $this->ogDescription, $this->ogImage, $this->ogUrl, $this->jsonLd);
    }

    public function withoutRobots(): self
    {
        return new self($this->title, $this->description, $this->canonical, null, $this->ogType, $this->ogTitle, $this->ogDescription, $this->ogImage, $this->ogUrl, $this->jsonLd);
    }
}
