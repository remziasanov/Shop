<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Models\Customer;
use App\Models\Export;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => ExportType::Catalog,
            'status' => ExportStatus::Queued,
            'params' => [],
            'rows' => 0,
        ];
    }

    /**
     * CX-06: an export of a customer (no staff owner), valid for 24 hours.
     *
     * @param  array<string, mixed>  $params
     */
    public function forCustomer(Customer $customer, ExportType $type = ExportType::CustomerOrdersPdf, array $params = []): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'customer_id' => $customer->id,
            'type' => $type,
            'params' => $params + ['from' => '2026-01-01', 'until' => '2026-03-31', 'types' => [], 'statuses' => [], 'with_items' => false],
            'expires_at' => now()->addDay(),
        ]);
    }

    public function status(ExportStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
