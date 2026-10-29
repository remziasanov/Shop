<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerCompany;

/**
 * Company requisites of a customer (CA-10): one set per customer. The values are validated by the form request
 * (INN, KPP, OGRN rules); this service stores them.
 */
final class CustomerCompanyService
{
    public function find(Customer $customer): ?CustomerCompany
    {
        return CustomerCompany::query()->where('customer_id', $customer->id)->first();
    }

    /**
     * @param  array{name: string, inn: string, kpp?: string|null, ogrn?: string|null, legal_address: string}  $data
     */
    public function save(Customer $customer, array $data): CustomerCompany
    {
        $company = $this->find($customer) ?? new CustomerCompany;
        $company->fill([
            'name' => trim($data['name']),
            'inn' => $data['inn'],
            'kpp' => $this->blankToNull($data['kpp'] ?? null),
            'ogrn' => $this->blankToNull($data['ogrn'] ?? null),
            'legal_address' => trim($data['legal_address']),
        ]);
        $company->customer_id = $customer->id;
        $company->save();

        return $company;
    }

    public function delete(Customer $customer): void
    {
        CustomerCompany::query()->where('customer_id', $customer->id)->delete();
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
