<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PromoBatchStatus;
use App\Services\Promo\PromoBatchService;
use Database\Factories\PromoCodeBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bulk generation (PM-15). `template` holds the shared conditions of the codes as JSON (see
 * {@see PromoBatchService} for the keys); `generated` is the progress.
 *
 * @property int $id
 * @property string $name
 * @property string $prefix
 * @property int $count
 * @property int $code_length
 * @property array<string, mixed>|null $template
 * @property int $generated
 * @property PromoBatchStatus $status
 * @property int|null $created_by
 */
class PromoCodeBatch extends Model
{
    /** @use HasFactory<PromoCodeBatchFactory> */
    use HasFactory;

    protected $fillable = ['name', 'prefix', 'count', 'code_length', 'template', 'generated', 'status', 'created_by'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'template' => 'array',
            'count' => 'integer',
            'code_length' => 'integer',
            'generated' => 'integer',
            'status' => PromoBatchStatus::class,
        ];
    }

    /**
     * @return HasMany<PromoCode, $this>
     */
    public function codes(): HasMany
    {
        return $this->hasMany(PromoCode::class, 'batch_id');
    }
}
