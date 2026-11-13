<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GroupPriceBulkOperation;
use App\Models\CustomerGroup;
use App\Services\Pricing\GroupPriceBulkChanger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Applies the bulk action «Цены группы» (GP-18) to up to {@see GroupPriceBulkChanger::CHUNK_SIZE} products. The
 * outcome depends only on the current prices, so a retry or a duplicate run changes nothing twice (the second run finds
 * the exact prices already in place). The acting user is passed explicitly: queue workers have no session.
 */
class ApplyGroupPriceBulkChange implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 40;

    /**
     * @param  list<int>  $productIds
     */
    public function __construct(
        public array $productIds,
        public int $groupId,
        public string $operation,
        public ?string $value,
        public ?int $userId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(GroupPriceBulkChanger $changer): void
    {
        $group = CustomerGroup::query()->find($this->groupId);
        $operation = GroupPriceBulkOperation::tryFrom($this->operation);

        if ($group === null || $operation === null) {
            return;
        }

        $result = $changer->apply($this->productIds, $group, $operation, $this->value, $this->userId);

        if ($result->skippedTotal() > 0) {
            Log::info('Group price bulk change skipped items.', ['skipped' => $result->skipped, 'group' => $this->groupId]);
        }
    }
}
