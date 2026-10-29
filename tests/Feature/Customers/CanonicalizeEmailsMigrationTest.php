<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Models\CustomerVerificationCode;
use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The data migration that brings stored addresses to the canonical ASCII form (CA-05…CA-14).
 */
function runCanonicalizeMigration(): void
{
    /** @var Migration $migration */
    $migration = require base_path('database/migrations/2026_11_13_100001_canonicalize_stored_emails.php');
    $migration->up();
}

it('rewrites stored addresses to canonical form, leaves the rest alone and is idempotent', function (): void {
    $upper = makeCustomer(['email' => 'Mixed.Case@Example.com']);
    $idn = makeCustomer(['email' => 'ivan@ПОЧТА.рф']);
    $broken = makeCustomer(['email' => 'иван@example.com']);
    $clean = makeCustomer(['email' => 'clean@example.com']);
    $taken = makeCustomer(['email' => 'taken@example.com']);
    $collides = makeCustomer(['email' => 'Taken@Example.com ']);
    $order = Order::factory()->create(['customer_email' => 'Buyer@GMÁIL.com']);
    $noEmail = Order::factory()->create(['customer_email' => null]);

    runCanonicalizeMigration();

    expect($upper->fresh()->email)->toBe('mixed.case@example.com')
        ->and($idn->fresh()->email)->toBe('ivan@xn--80a1acny.xn--p1ai')
        ->and($broken->fresh()->email)->toBe('иван@example.com')
        ->and($clean->fresh()->email)->toBe('clean@example.com')
        ->and($taken->fresh()->email)->toBe('taken@example.com')
        ->and($order->fresh()->customer_email)->toBe('buyer@xn--gmil-6na.com')
        ->and($noEmail->fresh()->customer_email)->toBeNull();

    $before = Customer::query()->orderBy('id')->pluck('email')->all();
    runCanonicalizeMigration();

    expect(Customer::query()->orderBy('id')->pluck('email')->all())->toBe($before);
})->skip(fn (): bool => DB::getDriverName() === 'mysql', 'The SQLite unique index compares bytes; the MySQL collation case is covered below');

it('drops the pending codes of a non-canonical address and keeps the canonical ones', function (): void {
    $make = fn (string $email) => CustomerVerificationCode::query()->forceCreate([
        'customer_id' => null, 'email' => $email, 'purpose' => VerificationPurpose::Login, 'code_hash' => str_repeat('a', 64),
        'attempts' => 0, 'expires_at' => now()->addMinutes(10), 'sent_at' => now(), 'ip' => '192.0.2.1',
    ]);
    $make('Old@Example.com');
    $make('fine@example.com');

    runCanonicalizeMigration();

    expect(CustomerVerificationCode::query()->pluck('email')->all())->toBe(['fine@example.com']);
});
