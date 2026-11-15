<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Customer;
use App\Models\CustomerCompany;
use App\Models\Order;

/**
 * The payer requisites of an order as they were when the order was placed (CX-10, CX-11): a reprinted invoice must not
 * show requisites the customer changed later. Stored in `orders.payer_snapshot` as `{"company": null | {...}}`.
 */
final class PayerSnapshot
{
    /**
     * @return array{company: array{name: string, inn: string, kpp: string|null, ogrn: string|null, legal_address: string}|null}
     */
    public static function for(Customer $customer): array
    {
        $company = $customer->company()->first();

        return ['company' => $company === null ? null : [
            'name' => $company->name,
            'inn' => $company->inn,
            'kpp' => $company->kpp,
            'ogrn' => $company->ogrn,
            'legal_address' => $company->legal_address,
        ]];
    }

    /**
     * The payer company to print: the snapshot when the order has one (it may say «no company»), otherwise the current
     * requisites of the customer.
     */
    public static function companyFor(Order $order): ?CustomerCompany
    {
        $snapshot = $order->payer_snapshot;

        if (! is_array($snapshot)) {
            return $order->customer?->company;
        }

        $company = $snapshot['company'] ?? null;

        return is_array($company) ? new CustomerCompany($company) : null;
    }
}
