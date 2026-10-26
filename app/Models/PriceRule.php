<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\PriceRuleTargetType;
use App\Enums\ProductFlag;
use App\Observers\PriceRuleCacheObserver;
use Carbon\CarbonInterface;
use Database\Factories\PriceRuleFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Price rule (TZ 6.8). `value` is a decimal(12,4) string: percent or rubles depending on the action.
 *
 * Saving or deleting a rule or its targets through Eloquent flushes the cached rule set
 * (`PriceRuleRepository`); after raw query-builder changes call `PriceRuleRepository::flush()`.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property PriceRuleAction $action
 * @property string $value
 * @property PriceRuleScope $scope
 * @property bool $include_subcategories
 * @property int $priority
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property bool $show_as_discount
 * @property bool $stop_processing
 * @property-read Collection<int, PriceRuleTarget> $targets
 */
#[ObservedBy(PriceRuleCacheObserver::class)]
class PriceRule extends Model
{
    /** @use HasFactory<PriceRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'is_active',
        'action',
        'value',
        'scope',
        'include_subcategories',
        'priority',
        'starts_at',
        'ends_at',
        'show_as_discount',
        'stop_processing',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'action' => PriceRuleAction::class,
            'value' => 'decimal:4',
            'scope' => PriceRuleScope::class,
            'include_subcategories' => 'boolean',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'show_as_discount' => 'boolean',
            'stop_processing' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PriceRuleTarget, $this>
     */
    public function targets(): HasMany
    {
        return $this->hasMany(PriceRuleTarget::class);
    }

    /**
     * Enabled rules (the period is checked against the current time by the calculator).
     *
     * @param  Builder<PriceRule>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('is_active'), true);
    }

    /**
     * Enabled rules whose period contains $now (starts_at <= now < ends_at).
     *
     * @param  Builder<PriceRule>  $query
     */
    public function scopeInEffect(Builder $query, CarbonInterface $now): void
    {
        $query->active()
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /**
     * @return list<int>
     */
    public function categoryIds(): array
    {
        return $this->targetIds(PriceRuleTargetType::Category);
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        return $this->targetIds(PriceRuleTargetType::Product);
    }

    /**
     * Brand names as entered.
     *
     * @return list<string>
     */
    public function brands(): array
    {
        return $this->targetValues(PriceRuleTargetType::Brand);
    }

    /**
     * @return list<ProductFlag>
     */
    public function flags(): array
    {
        $flags = [];

        foreach ($this->targetValues(PriceRuleTargetType::Flag) as $value) {
            $flag = ProductFlag::tryFrom($value);

            if ($flag !== null && ! in_array($flag, $flags, true)) {
                $flags[] = $flag;
            }
        }

        return $flags;
    }

    /**
     * @return list<int>
     */
    private function targetIds(PriceRuleTargetType $type): array
    {
        $this->loadMissing('targets');

        return $this->targets
            ->filter(fn (PriceRuleTarget $target): bool => $target->target_type === $type && $target->target_id !== null)
            ->map(fn (PriceRuleTarget $target): int => (int) $target->target_id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function targetValues(PriceRuleTargetType $type): array
    {
        $this->loadMissing('targets');

        return $this->targets
            ->filter(fn (PriceRuleTarget $target): bool => $target->target_type === $type && trim((string) $target->target_value) !== '')
            ->map(fn (PriceRuleTarget $target): string => (string) $target->target_value)
            ->unique()
            ->values()
            ->all();
    }
}
