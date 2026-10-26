<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\PriceRule;
use App\Observers\PriceRuleCacheObserver;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Enabled price rules with their targets, loaded once (2 queries), then served from process memory and
 * the cache (D-026). The period is not filtered here: the calculator checks it against the passed time.
 *
 * Register as a singleton. The cache entry is dropped by {@see PriceRuleCacheObserver} on every
 * rule/target save or delete; call {@see flush()} after raw query-builder changes to the rule tables.
 */
final class PriceRuleRepository
{
    public const CACHE_KEY = 'pricing.rules';

    /** @var list<PriceRuleData>|null */
    private ?array $memo = null;

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @return list<PriceRuleData> ordered by id
     */
    public function activeRules(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            return PriceRule::query()
                ->active()
                ->with('targets')
                ->orderBy('id')
                ->get()
                ->map(fn (PriceRule $rule): array => PriceRuleData::fromModel($rule)->toArray())
                ->all();
        });

        return $this->memo = array_map(PriceRuleData::fromArray(...), $rows);
    }

    /**
     * Drop the cache entry and the in-memory copy.
     */
    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->forgetMemo();
    }

    /**
     * Drop only the in-memory copy (queue workers do this before every job).
     */
    public function forgetMemo(): void
    {
        $this->memo = null;
    }
}
