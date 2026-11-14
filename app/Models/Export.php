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
use Illuminate\Support\Str;

/**
 * Export journal row (EX-03, CX-06): one journal for the exports of staff (file kept for 7 days) and of customers
 * (`customer_id` set, the file is available until `expires_at`, 24 hours by default).
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $customer_id owner of a customer export (CX-06); null for a staff export
 * @property string|null $uuid public identifier in the download link of a customer export (CX-07)
 * @property CarbonInterface|null $expires_at end of the download period of a customer export
 * @property int|null $file_size size of the file in bytes
 * @property ExportType $type
 * @property ExportStatus $status
 * @property array<string, mixed>|null $params
 * @property string|null $file_path
 * @property int $rows
 * @property string|null $failure_message
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 * @property-read Customer|null $customer
 */
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'customer_id', 'uuid', 'expires_at', 'file_size', 'type', 'status', 'params', 'file_path', 'rows', 'failure_message'];

    protected static function booted(): void
    {
        static::creating(function (Export $export): void {
            if (empty($export->uuid)) {
                $export->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ExportType::class,
            'status' => ExportStatus::class,
            'params' => 'array',
            'expires_at' => 'datetime',
            'file_size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * An export of a customer (CX-06), not of a staff member.
     */
    public function isCustomerExport(): bool
    {
        return $this->customer_id !== null;
    }
}
