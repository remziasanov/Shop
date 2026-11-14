<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TelegramUpdateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A processed webhook update id, kept for deduplication (TG-04). The body is never stored.
 *
 * @property int $id
 * @property int $update_id
 * @property string $type
 * @property CarbonInterface $received_at
 */
class TelegramUpdate extends Model
{
    /** @use HasFactory<TelegramUpdateFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['update_id', 'type', 'received_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['update_id' => 'integer', 'received_at' => 'datetime'];
    }
}
