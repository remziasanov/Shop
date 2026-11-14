<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use App\Enums\ExportType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Services\Export\CustomerOrdersExporter;
use Illuminate\Validation\Rule;

/**
 * CX-01: the «Выгрузить» form of the order history: period, format, types, statuses, «с составом». Whether the size
 * fits the limits (CX-05) is decided by the export service after the validation.
 *
 * @phpstan-import-type Params from CustomerOrdersExporter
 */
class CustomerExportRequest extends AccountRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(['with_items' => $this->boolean('with_items')]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['required', Rule::in(['pdf', 'xlsx'])],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::enum(OrderType::class)],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => ['string', Rule::enum(OrderStatus::class)],
            'with_items' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_map(fn (string $key): string => __('customer_export.attributes.'.$key), [
            'from' => 'from',
            'until' => 'until',
            'format' => 'format',
            'types' => 'types',
            'statuses' => 'statuses',
            'with_items' => 'with_items',
        ]);
    }

    public function exportType(): ExportType
    {
        return $this->validated('format') === 'pdf' ? ExportType::CustomerOrdersPdf : ExportType::CustomerOrdersXlsx;
    }

    /**
     * @return Params
     */
    public function exportParams(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return [
            'from' => (string) $data['from'],
            'until' => (string) $data['until'],
            'types' => array_values(array_unique(array_map('strval', (array) ($data['types'] ?? [])))),
            'statuses' => array_values(array_unique(array_map('strval', (array) ($data['statuses'] ?? [])))),
            'with_items' => (bool) ($data['with_items'] ?? false),
        ];
    }
}
