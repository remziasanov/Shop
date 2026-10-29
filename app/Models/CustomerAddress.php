<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An entry of the address book (CA-09). `customer_id` is set by the service from the signed-in customer.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $label
 * @property string $recipient_name
 * @property string $phone
 * @property string $address
 * @property bool $is_default
 */
#[Fillable(['label', 'recipient_name', 'phone', 'address', 'is_default'])]
class CustomerAddress extends Model
{
    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory;

    /** CA-09: the most addresses of one customer. */
    public const MAX_PER_CUSTOMER = 10;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'customer_id' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
