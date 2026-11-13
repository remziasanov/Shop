<?php

declare(strict_types=1);

namespace App\Services\Promo;

use App\Enums\PromoBatchStatus;
use App\Jobs\GeneratePromoCodeBatch;
use App\Models\PromoCodeBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The work of {@see GeneratePromoCodeBatch} (PM-15): random codes in chunks of 500, each chunk in one
 * transaction under the batch row lock. The progress is the number of codes the batch really has, so a repeated or
 * interrupted run continues where it stopped and never creates more than `count` codes. Uniqueness: candidates that
 * collide with an existing code are dropped by `INSERT IGNORE` semantics and replaced in the next round.
 */
final class PromoBatchGenerator
{
    public const CHUNK = 500;

    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Rounds per chunk before giving up (a collision rate this high means the code space is exhausted). */
    private const MAX_ROUNDS = 20;

    public function __construct(private readonly PromoBatchService $batches) {}

    /**
     * Generates chunks until the batch is complete or the deadline (microtime) has passed.
     *
     * @return bool whether the batch is complete
     */
    public function run(PromoCodeBatch $batch, ?float $deadline = null): bool
    {
        $batch->status = PromoBatchStatus::Running;
        $batch->save();

        do {
            $remaining = $this->chunk($batch);

            if ($remaining === 0) {
                $batch->refresh();
                $batch->status = PromoBatchStatus::Done;
                $batch->save();

                return true;
            }
        } while ($deadline === null || microtime(true) < $deadline);

        return false;
    }

    /**
     * One chunk; returns how many codes the batch still lacks afterwards.
     */
    public function chunk(PromoCodeBatch $batch): int
    {
        return DB::transaction(function () use ($batch): int {
            $locked = PromoCodeBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $existing = (int) DB::table('promo_codes')->where('batch_id', $locked->id)->count();
            $want = min(self::CHUNK, $locked->count - $existing);

            if ($want > 0) {
                $this->insertCodes($locked, $want);
                $existing = (int) DB::table('promo_codes')->where('batch_id', $locked->id)->count();
            }

            $locked->generated = $existing;
            $locked->save();
            $batch->generated = $existing;

            return max(0, $locked->count - $existing);
        });
    }

    private function insertCodes(PromoCodeBatch $batch, int $want): void
    {
        $template = $this->batches->columns($batch->template ?? []);
        $inserted = 0;

        for ($round = 0; $round < self::MAX_ROUNDS && $inserted < $want; $round++) {
            $codes = [];

            while (count($codes) < $want - $inserted) {
                $codes[$batch->prefix.$this->randomPart($batch->code_length)] = true;
            }

            $rows = [];

            foreach (array_keys($codes) as $code) {
                $rows[] = $template + [
                    'code' => $code,
                    'name' => $batch->name,
                    'usage_limit' => 1,
                    'usage_count' => 0,
                    'per_customer_limit' => 1,
                    'batch_id' => $batch->id,
                    'created_by' => $batch->created_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            $inserted += DB::table('promo_codes')->insertOrIgnore($rows);
        }

        if ($inserted < $want) {
            throw new RuntimeException('Could not find enough unique promo codes; use a longer random part.');
        }

        // Groups and targets for the codes of this batch that have none yet (this chunk; atomic with the insert).
        $ids = DB::table('promo_codes')
            ->where('batch_id', $batch->id)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('promo_code_groups')->whereColumn('promo_code_groups.promo_code_id', 'promo_codes.id'))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->batches->replaceRelations($ids, $batch->template ?? []);
    }

    private function randomPart(int $length): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $part = '';

        for ($i = 0; $i < $length; $i++) {
            $part .= self::ALPHABET[random_int(0, $max)];
        }

        return $part;
    }
}
