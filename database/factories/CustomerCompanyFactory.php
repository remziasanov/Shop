<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The default INN is a valid 10-digit test number (checksum verified by the requisites validator of the cabinet).
 *
 * @extends Factory<CustomerCompany>
 */
class CustomerCompanyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => 'ООО «'.fake()->company().'»',
            'inn' => '1234567894',
            'kpp' => '123401001',
            'ogrn' => null,
            'legal_address' => 'г. Тестоград, ул. Примерная, д. 1',
        ];
    }
}
