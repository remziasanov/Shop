<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomerCompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Company requisites of a customer (CA-10): one set per customer. Validation of INN / KPP / OGRN is the job of the
 * requisites validator of the cabinet; this model only stores the values.
 *
 * @property int $id
 * @property int $customer_id
 * @property string $name
 * @property string $inn
 * @property string|null $kpp
 * @property string|null $ogrn
 * @property string $legal_address
 */
#[Fillable(['name', 'inn', 'kpp', 'ogrn', 'legal_address'])]
class CustomerCompany extends Model
{
    /** @use HasFactory<CustomerCompanyFactory> */
    use HasFactory;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['customer_id' => 'integer'];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
