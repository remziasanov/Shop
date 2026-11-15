<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * TD-09: the schema of the tests has the same foreign keys as production (SQLite included).
 */
it('has the foreign keys of carts, orders and users on every driver', function (string $table, string $column, string $foreignTable): void {
    $found = collect(Schema::getForeignKeys($table))
        ->contains(fn (array $key): bool => $key['columns'] === [$column] && $key['foreign_table'] === $foreignTable);

    expect($found)->toBeTrue();
})->with([
    'carts.customer_id' => ['carts', 'customer_id', 'customers'],
    'orders.customer_id' => ['orders', 'customer_id', 'customers'],
    'users.role_id' => ['users', 'role_id', 'roles'],
]);
