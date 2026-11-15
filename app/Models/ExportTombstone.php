<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * What stays of a customer export after the nightly cleanup removed its row (TZ 18.7, CX-07): only the public uuid,
 * the owner and the end of the download period, so the old link answers 410 instead of 404. Kept for a few days.
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_id
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 */
class ExportTombstone extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['uuid', 'customer_id', 'expires_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['customer_id' => 'integer', 'expires_at' => 'datetime'];
    }
}
