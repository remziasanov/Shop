<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Exceptions\CustomerAccountException;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The address book (CA-09): up to {@see CustomerAddress::MAX_PER_CUSTOMER} addresses, exactly one default when there
 * is any. Every query is scoped by the customer (CA-28): an id of another customer's address is not found.
 */
final class CustomerAddressService
{
    /**
     * @return Collection<int, CustomerAddress> default first
     */
    public function listFor(Customer $customer): Collection
    {
        return CustomerAddress::query()->where('customer_id', $customer->id)->orderByDesc('is_default')->orderBy('id')->get();
    }

    /**
     * The address of the customer or null.
     */
    public function find(Customer $customer, int $id): ?CustomerAddress
    {
        return CustomerAddress::query()->where('customer_id', $customer->id)->whereKey($id)->first();
    }

    /**
     * @param  array{label: string, recipient_name: string, phone: string, address: string, is_default?: bool}  $data
     *
     * @throws CustomerAccountException the limit is reached
     */
    public function create(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data): CustomerAddress {
            // Serialize parallel additions of one customer, so the limit cannot be exceeded.
            Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            $count = CustomerAddress::query()->where('customer_id', $customer->id)->count();

            if ($count >= CustomerAddress::MAX_PER_CUSTOMER) {
                throw new CustomerAccountException(__('account.addresses.limit', ['max' => CustomerAddress::MAX_PER_CUSTOMER]));
            }

            $address = new CustomerAddress($this->fields($data));
            $address->customer_id = $customer->id;
            // The first address is the default one.
            $address->is_default = $count === 0 || (bool) ($data['is_default'] ?? false);
            $address->save();

            if ($address->is_default) {
                $this->makeOnlyDefault($address);
            }

            return $address;
        });
    }

    /**
     * @param  array{label: string, recipient_name: string, phone: string, address: string, is_default?: bool}  $data
     */
    public function update(CustomerAddress $address, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($address, $data): CustomerAddress {
            $address->fill($this->fields($data))->save();

            if (! empty($data['is_default'])) {
                $this->makeDefault($address);
            }

            return $address;
        });
    }

    public function makeDefault(CustomerAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $address->forceFill(['is_default' => true])->save();
            $this->makeOnlyDefault($address);
        });
    }

    /**
     * Deletes the address; when it was the default one, the oldest remaining address becomes the default.
     */
    public function delete(CustomerAddress $address): void
    {
        DB::transaction(function () use ($address): void {
            $wasDefault = $address->is_default;
            $customerId = $address->customer_id;
            $address->delete();

            if ($wasDefault) {
                $next = CustomerAddress::query()->where('customer_id', $customerId)->orderBy('id')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{label: string, recipient_name: string, phone: string, address: string}
     */
    private function fields(array $data): array
    {
        return [
            'label' => trim((string) $data['label']),
            'recipient_name' => trim((string) $data['recipient_name']),
            'phone' => (string) $data['phone'],
            'address' => trim((string) $data['address']),
        ];
    }

    private function makeOnlyDefault(CustomerAddress $address): void
    {
        CustomerAddress::query()
            ->where('customer_id', $address->customer_id)
            ->where('id', '!=', $address->id)
            ->where('is_default', true)
            ->update(['is_default' => false]);
    }
}
