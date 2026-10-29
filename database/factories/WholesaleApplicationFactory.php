<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WholesaleApplicationStatus;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Models\WholesaleApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fake applications only (the repository is public): example.com addresses and a valid test INN.
 *
 * @extends Factory<WholesaleApplication>
 */
class WholesaleApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'status' => WholesaleApplicationStatus::New->value,
            'company_name' => 'ООО «'.fake()->company().'»',
            'inn' => '7707083893',
            'kpp' => '770701001',
            'contact_name' => fake()->name(),
            'phone' => '+7900'.fake()->unique()->numerify('#######'),
            'email' => fake()->unique()->safeEmail(),
            'comment' => null,
            'consent_at' => now(),
            'consent_ip' => '192.0.2.1',
            'consent_user_agent' => 'Mozilla/5.0 (test)',
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => ['customer_id' => $customer->id, 'email' => $customer->email]);
    }

    public function withInn(string $inn, ?string $kpp = null): static
    {
        return $this->state(fn (): array => ['inn' => $inn, 'kpp' => $kpp]);
    }

    public function approved(?CustomerGroup $group = null, ?User $by = null): static
    {
        return $this->state(fn (): array => [
            'status' => WholesaleApplicationStatus::Approved->value,
            'decided_at' => now(),
            'decided_by' => $by?->id,
            'decided_group_id' => $group?->id,
        ]);
    }

    public function rejected(string $reason = 'Не удалось подтвердить компанию', ?User $by = null): static
    {
        return $this->state(fn (): array => [
            'status' => WholesaleApplicationStatus::Rejected->value,
            'decided_at' => now(),
            'decided_by' => $by?->id,
            'reject_reason' => $reason,
        ]);
    }
}
