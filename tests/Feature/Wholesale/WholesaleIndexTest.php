<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * TD-12 (TZ 25.5): the applications table is indexed by `(status, created_at)`.
 */
it('has the (status, created_at) index on wholesale applications', function (): void {
    $columns = collect(Schema::getIndexes('wholesale_applications'))->map(fn (array $index): array => $index['columns']);

    expect($columns->contains(['status', 'created_at']))->toBeTrue()
        ->and($columns->contains(['status']))->toBeFalse();
});
