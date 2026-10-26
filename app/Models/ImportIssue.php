<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportIssueLevel;
use Carbon\CarbonInterface;
use Database\Factories\ImportIssueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An error or warning of one import row (IM-12).
 *
 * @property int $id
 * @property int $import_id
 * @property int $row_number
 * @property string|null $column
 * @property ImportIssueLevel $level
 * @property string $message
 * @property string|null $value
 * @property CarbonInterface|null $created_at
 * @property-read Import $import
 */
class ImportIssue extends Model
{
    /** @use HasFactory<ImportIssueFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['import_id', 'row_number', 'column', 'level', 'message', 'value'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => ImportIssueLevel::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
