<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;

/**
 * Result of {@see AttributeStats::infer()} (FL-04): the type, the unit of numbers, whether the characteristic may
 * be a filter and why not.
 */
final class AttributeTypeInference
{
    public const REASON_DISTINCT = 'distinct';

    public const REASON_LONG = 'long';

    /**
     * @param  float  $numericShare  share of the values that parse as a number with the common unit (0…1)
     * @param  bool  $disputed  the type is not clear-cut: some values do not fit it (or the share is borderline)
     * @param  string|null  $nonFilterableReason  one of the REASON_* constants when $filterable is false
     */
    public function __construct(
        public readonly AttributeType $type,
        public readonly ?string $unit,
        public readonly bool $filterable,
        public readonly int $distinct,
        public readonly float $numericShare,
        public readonly bool $disputed,
        public readonly ?string $nonFilterableReason = null,
    ) {}
}
