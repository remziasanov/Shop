<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VerificationPurpose;
use App\Services\Customers\VerificationCodeService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A one-time e-mail code (CA-04). Only the HMAC of the code is stored. Written exclusively by
 * {@see VerificationCodeService}, so nothing is mass assignable.
 *
 * @property int $id
 * @property int|null $customer_id
 * @property string $email
 * @property VerificationPurpose $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface $sent_at
 * @property CarbonInterface|null $consumed_at
 * @property string $ip
 * @property array<string, mixed>|null $payload
 * @property CarbonInterface|null $created_at
 */
class CustomerVerificationCode extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> Nothing is mass assignable: the code service writes the row with forceFill(). */
    protected $fillable = [];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'purpose' => VerificationPurpose::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'consumed_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * Not consumed (or invalidated) and not expired.
     *
     * @param  Builder<CustomerVerificationCode>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('consumed_at'))->where($query->qualifyColumn('expires_at'), '>', now());
    }
}
