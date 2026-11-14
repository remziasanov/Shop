<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * What a customer export request comes to (CX-04, CX-05): how many requests it holds, whether it is built in the web
 * request or in the queue, or the message why it is refused.
 */
final readonly class ExportPlan
{
    public function __construct(
        public int $orders,
        public bool $sync,
        public ?string $error = null,
    ) {}

    public function isAllowed(): bool
    {
        return $this->error === null;
    }
}
