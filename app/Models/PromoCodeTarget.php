<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoTargetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A category / product (`target_id`) or brand (`target_value`) a promo code is limited to (PM-03).
 *
 * @property int $id
 * @property int $promo_code_id
 * @property PromoTargetType $target_type
 * @property int|null $target_id
 * @property string|null $target_value
 */
class PromoCodeTarget extends Model
{
    public $timestamps = false;

    protected $fillable = ['promo_code_id', 'target_type', 'target_id', 'target_value'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['target_type' => PromoTargetType::class, 'target_id' => 'integer'];
    }

    /**
     * @return BelongsTo<PromoCode, $this>
     */
    public function promoCode(): BelongsTo
    {
        return $this->belongsTo(PromoCode::class);
    }
}
