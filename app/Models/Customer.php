<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Customers\CustomerAuthService;
use Carbon\CarbonInterface;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * Storefront customer (CA-02, 17.5): a separate table and the `customer` guard, never mixed with the staff `users`
 * (D-071). It does not implement the Filament user contract, so it cannot enter the admin panel.
 *
 * Only the profile fields are mass assignable. The group, e-mail, verification, block and deletion fields are
 * written by {@see CustomerAuthService} and the admin services with forceFill().
 *
 * @property int $id
 * @property string $uuid
 * @property int $customer_group_id
 * @property string $name
 * @property string $email
 * @property CarbonInterface|null $email_verified_at
 * @property string|null $phone
 * @property string $password
 * @property int $auth_version
 * @property bool $is_blocked
 * @property string|null $blocked_reason
 * @property CarbonInterface|null $consent_at
 * @property string|null $consent_ip
 * @property CarbonInterface|null $last_login_at
 * @property CarbonInterface|null $deletion_requested_at
 * @property CarbonInterface|null $anonymized_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'phone'])]
#[Hidden(['password', 'remember_token', 'blocked_reason'])]
class Customer extends Authenticatable
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Customer $customer): void {
            if (empty($customer->uuid)) {
                $customer->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'customer_group_id' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'auth_version' => 'integer',
            'is_blocked' => 'boolean',
            'consent_at' => 'datetime',
            'last_login_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<CustomerGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderBy('id');
    }

    /**
     * @return HasOne<CustomerCompany, $this>
     */
    public function company(): HasOne
    {
        return $this->hasOne(CustomerCompany::class);
    }

    /**
     * @return HasMany<CustomerNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(CustomerNotification::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<WholesaleApplication, $this>
     */
    public function wholesaleApplications(): HasMany
    {
        return $this->hasMany(WholesaleApplication::class)->orderByDesc('id');
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * The only gate to the cabinet and to orders under the account (CA-03): confirmed, not blocked, not anonymized.
     */
    public function canSignIn(): bool
    {
        return $this->hasVerifiedEmail() && ! $this->is_blocked && $this->anonymized_at === null;
    }

    /**
     * CA-31: ends every other session of the customer (the stored version no longer matches) and the «remember me»
     * cookies. Atomic; the caller updates the current session with the new value if it stays signed in.
     */
    public function bumpAuthVersion(): void
    {
        $this->increment('auth_version');
        $this->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
