<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use Carbon\CarbonInterface;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Background export journal row (EX-03): the file is kept for 7 days.
 *
 * @property int $id
 * @property int|null $user_id
 * @property ExportType $type
 * @property ExportStatus $status
 * @property array<string, mixed>|null $params
 * @property string|null $file_path
 * @property int $rows
 * @property string|null $failure_message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 */
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'type', 'status', 'params', 'file_path', 'rows', 'failure_message'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'status' => ExportStatus::class,
            'params' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
