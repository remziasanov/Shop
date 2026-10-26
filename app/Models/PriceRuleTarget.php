<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PriceRuleTargetType;
use App\Observers\PriceRuleCacheObserver;
use Database\Factories\PriceRuleTargetFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One target of a price rule: a category/product id, a brand name or a product flag code.
 *
 * @property int $id
 * @property int $price_rule_id
 * @property PriceRuleTargetType $target_type
 * @property int|null $target_id
 * @property string|null $target_value
 * @property-read PriceRule $rule
 */
#[ObservedBy(PriceRuleCacheObserver::class)]
class PriceRuleTarget extends Model
{
    /** @use HasFactory<PriceRuleTargetFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['price_rule_id', 'target_type', 'target_id', 'target_value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => PriceRuleTargetType::class,
            'target_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PriceRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(PriceRule::class, 'price_rule_id');
    }
}
