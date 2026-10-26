<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Import;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An import reached a final state (completed, completed with errors or failed); check `$import->status`.
 * Fired after the catalog changes are committed and prices of touched products are queued (IM-14); the
 * sitemap generation of stage 5 subscribes to it.
 */
final class ImportFinished
{
    use Dispatchable;

    public function __construct(public readonly Import $import) {}
}
