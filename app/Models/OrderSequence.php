<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrderSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Last issued number per request type (D-045).
 *
 * @property int $id
 * @property string $type
 * @property int $last_number
 */
class OrderSequence extends Model
{
    /** @use HasFactory<OrderSequenceFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['type', 'last_number'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_number' => 'integer'];
    }
}
