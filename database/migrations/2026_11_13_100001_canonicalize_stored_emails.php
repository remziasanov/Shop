<?php

declare(strict_types=1);

use App\Services\Customers\EmailNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CA-05…CA-14: brings the e-mail addresses already stored to the canonical ASCII form of {@see EmailNormalizer}
 * (lower case, punycode domain), so that lookups and the unique index cannot be fooled by accent or case look-alikes.
 *
 *  - customers.email: rewritten unless that would collide with another customer (that row stays as it is);
 *  - orders.customer_email, wholesale_applications.email: rewritten;
 *  - customer_verification_codes: the code digest covers the address, so a code with a non-canonical address cannot
 *    be rewritten; such (short-lived, single-use) codes are deleted.
 *
 * Rows that cannot be canonicalised are left untouched. Idempotent: a second run finds nothing to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite('customers', 'email', unique: true);
        $this->rewrite('orders', 'customer_email');
        $this->rewrite('wholesale_applications', 'email');

        DB::table('customer_verification_codes')->orderBy('id')->chunkById(500, function ($rows): void {
            $stale = [];

            foreach ($rows as $row) {
                if (EmailNormalizer::normalize((string) $row->email) !== $row->email) {
                    $stale[] = $row->id;
                }
            }

            if ($stale !== []) {
                DB::table('customer_verification_codes')->whereIn('id', $stale)->delete();
            }
        });
    }

    /**
     * Intentionally a no-op: the original spelling of an address is not kept, and the canonical form is valid input
     * for the code before this migration.
     */
    public function down(): void {}

    private function rewrite(string $table, string $column, bool $unique = false): void
    {
        DB::table($table)->whereNotNull($column)->orderBy('id')->chunkById(500, function ($rows) use ($table, $column, $unique): void {
            foreach ($rows as $row) {
                $current = (string) $row->{$column};
                $canonical = EmailNormalizer::normalize($current);

                if ($canonical === null || $canonical === $current) {
                    continue;
                }

                // A collation-insensitive match with another row means the canonical form is (or looks) taken.
                if ($unique && DB::table($table)->where($column, $canonical)->where('id', '!=', $row->id)->exists()) {
                    continue;
                }

                DB::table($table)->where('id', $row->id)->update([$column => $canonical]);
            }
        });
    }
};
