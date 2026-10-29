<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WholesaleApplicationStatus;
use Carbon\CarbonInterface;
use Database\Factories\WholesaleApplicationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Application of a customer for wholesale access (CG-20…CG-24). Only the fields the customer types are mass
 * assignable; the owner, the status and the decision are written by WholesaleApplicationService with forceFill().
 * The personal fields are nullable: the anonymization of the customer wipes them (CA-17).
 *
 * @property int $id
 * @property int $customer_id
 * @property WholesaleApplicationStatus $status
 * @property string|null $company_name
 * @property string|null $inn
 * @property string|null $kpp
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $comment
 * @property CarbonInterface|null $consent_at
 * @property string|null $consent_ip
 * @property string|null $consent_user_agent
 * @property int|null $decided_by
 * @property CarbonInterface|null $decided_at
 * @property int|null $decided_group_id
 * @property string|null $reject_reason
 * @property string|null $return_comment
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['company_name', 'inn', 'kpp', 'contact_name', 'phone', 'email', 'comment'])]
class WholesaleApplication extends Model
{
    /** @use HasFactory<WholesaleApplicationFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'status' => WholesaleApplicationStatus::class,
            'consent_at' => 'datetime',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'decided_group_id' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function decidedGroup(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'decided_group_id');
    }

    /**
     * @param  Builder<WholesaleApplication>  $query
     */
    public function scopeNew(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), WholesaleApplicationStatus::New->value);
    }

    public function isNew(): bool
    {
        return $this->status === WholesaleApplicationStatus::New;
    }

    public function isDecided(): bool
    {
        return $this->status !== WholesaleApplicationStatus::New;
    }
}
