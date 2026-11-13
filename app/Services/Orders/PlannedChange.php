<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderChangeKind;

/**
 * One row of the future change log (OE-07), also shown in the preview. Money values are plain rubles strings
 * («1290.00»), quantities plain integers; `field` tells the viewer how to format them.
 */
final readonly class PlannedChange
{
    public function __construct(
        public OrderChangeKind $kind,
        public ?string $field,
        public ?int $itemId,
        public ?string $label,
        public ?string $old,
        public ?string $new,
    ) {}
}
