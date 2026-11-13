<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Promo\PromoLinkStats;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clicks of a promo link for one day (PM-25); written only by {@see PromoLinkStats::recordClick()}
 * with an upsert. Composite primary key (promo_link_id, day), so the model is read-only.
 *
 * @property int $promo_link_id
 * @property CarbonInterface $day
 * @property int $clicks
 * @property int $unique_clicks
 */
class PromoLinkStat extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'promo_link_id';

    protected $fillable = ['promo_link_id', 'day', 'clicks', 'unique_clicks'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['day' => 'date', 'clicks' => 'integer', 'unique_clicks' => 'integer'];
    }

    /**
     * @return BelongsTo<PromoLink, $this>
     */
    public function link(): BelongsTo
    {
        return $this->belongsTo(PromoLink::class, 'promo_link_id');
    }
}
