<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * An error or a warning about a tier row (GP-14). `code` is stable (tests, import reports), `message` is Russian text
 * for forms; `minQty` names the row when the issue belongs to one.
 */
final readonly class TierIssue
{
    public function __construct(public string $code, public string $message, public ?int $minQty = null) {}
}
