<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * What one call of {@see ImportRunner::step()} did.
 */
enum ImportStep: string
{
    /** The «Категории» sheet was applied. */
    case Categories = 'categories';

    /** One chunk of product rows was applied. */
    case Chunk = 'chunk';

    /** A file of pending photo tasks was processed. */
    case Photos = 'photos';

    /** The import is finished (completed, completed with errors, failed or cancelled). */
    case Finished = 'finished';

    /** Another worker holds the processing lock; try again shortly. */
    case Busy = 'busy';

    /** Nothing to claim now, but work is still in flight elsewhere; try again shortly. */
    case Waiting = 'waiting';

    /** The import is not started (or not in a running state). */
    case Stopped = 'stopped';

    /**
     * Whether the step did real work, so the caller may immediately call again.
     */
    public function progressed(): bool
    {
        return in_array($this, [self::Categories, self::Chunk, self::Photos], true);
    }
}
