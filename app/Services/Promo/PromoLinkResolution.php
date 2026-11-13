<?php

declare(strict_types=1);

namespace App\Services\Promo;

/**
 * Where a promo link leads for a viewer (PM-22). When `ok`, `url` is the internal path with query and UTM
 * parameters (never an external address); otherwise `reason` is a key of `promo.link.reasons` and the visitor goes to
 * the catalog with the «Ссылка устарела» message.
 */
final readonly class PromoLinkResolution
{
    public function __construct(
        public bool $ok,
        public ?string $url,
        public ?string $reason = null,
    ) {}

    public static function to(string $url): self
    {
        return new self(true, $url);
    }

    public static function stale(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public function message(): string
    {
        return __('promo.link.stale');
    }

    public function reasonText(): ?string
    {
        return $this->reason === null ? null : __('promo.link.reasons.'.$this->reason);
    }
}
