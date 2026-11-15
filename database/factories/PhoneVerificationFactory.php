<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PhoneVerificationMethod;
use App\Enums\PhoneVerificationOutcome;
use App\Enums\PhoneVerificationPurpose;
use App\Models\PhoneVerification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhoneVerification>
 */
class PhoneVerificationFactory extends Factory
{
    protected $model = PhoneVerification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phone' => '+7999'.fake()->numerify('#######'),
            'purpose' => PhoneVerificationPurpose::GuestCheckout,
            'method' => PhoneVerificationMethod::FlashCall,
            'code_hash' => hash('sha256', fake()->unique()->uuid()),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'verified_at' => null,
            'outcome' => PhoneVerificationOutcome::Pending,
            'session_hash' => hash('sha256', fake()->uuid()),
            'provider_request_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'cost' => '1.3000',
            'ip' => fake()->ipv4(),
            'customer_id' => null,
            'note' => null,
        ];
    }

    public function skipped(): static
    {
        return $this->state(['outcome' => PhoneVerificationOutcome::SkippedProvider, 'provider_request_id' => null, 'cost' => null, 'code_hash' => null, 'note' => 'network']);
    }

    public function verified(): static
    {
        return $this->state(['outcome' => PhoneVerificationOutcome::Verified, 'verified_at' => now()]);
    }
}
