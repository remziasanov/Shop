<?php

declare(strict_types=1);

namespace App\Services\Telegram\Staff;

use App\Enums\StaffOrderAction;
use App\Models\Order;

/**
 * The outcome of one staff action (TS-03, TS-04). `order` is the fresh request when it exists.
 */
final class StaffActionResult
{
    public const DONE = 'done';

    /** The first tap of a dangerous action: nothing changed yet. */
    public const ARMED = 'armed';

    /** The request changed since the button was drawn (or the action does not fit its status any more): nothing was done. */
    public const STALE = 'stale';

    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const FAILED = 'failed';

    public function __construct(
        public readonly string $outcome,
        public readonly ?Order $order = null,
        public readonly ?StaffOrderAction $action = null,
        public readonly ?string $message = null,
    ) {}

    public function isDone(): bool
    {
        return $this->outcome === self::DONE;
    }
}
