<?php

declare(strict_types=1);

namespace App\Exceptions;

use Carbon\CarbonInterface;

/**
 * The order changed after the editor form was opened (OE-08): another save or a status change got there first.
 */
final class ConcurrentEditException extends SalesException
{
    public function __construct(public readonly ?string $userName, public readonly ?CarbonInterface $changedAt)
    {
        $time = $changedAt?->format('H:i') ?? '';

        parent::__construct(
            $userName !== null && $userName !== ''
                ? __('order_edit.errors.concurrent_by', ['name' => $userName, 'time' => $time])
                : __('order_edit.errors.concurrent', ['time' => $time]),
        );
    }
}
