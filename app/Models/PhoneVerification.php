<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PhoneVerificationMethod;
use App\Enums\PhoneVerificationOutcome;
use App\Enums\PhoneVerificationPurpose;
use App\Services\PhoneVerification\PhoneVerificationService;
use Carbon\CarbonInterface;
use Database\Factories\PhoneVerificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone verification attempt (PV-08). Only the HMAC of the code is stored. Written exclusively by
 * {@see PhoneVerificationService}, so nothing is mass assignable.
 *
 * @property int $id
 * @property string $phone
 * @property PhoneVerificationPurpose $purpose
 * @property PhoneVerificationMethod $method
 * @property string|null $code_hash
 * @property int $attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $verified_at
 * @property PhoneVerificationOutcome $outcome
 * @property string|null $session_hash
 * @property string|null $provider_request_id
 * @property string|null $cost
 * @property string|null $ip
 * @property int|null $customer_id
 * @property string|null $note
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class PhoneVerification extends Model
{
    /** @use HasFactory<PhoneVerificationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'purpose' => PhoneVerificationPurpose::class,
            'method' => PhoneVerificationMethod::class,
            'outcome' => PhoneVerificationOutcome::class,
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'cost' => 'decimal:4',
            'customer_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Sent, not answered and not expired.
     *
     * @param  Builder<PhoneVerification>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where($query->qualifyColumn('outcome'), PhoneVerificationOutcome::Pending->value)
            ->where($query->qualifyColumn('expires_at'), '>', now());
    }

    /**
     * Accepted by the provider: these are the rows the daily limit counts (PV-06).
     *
     * @param  Builder<PhoneVerification>  $query
     */
    public function scopeAcceptedByProvider(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('provider_request_id'));
    }
}
