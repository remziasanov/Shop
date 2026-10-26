<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CatalogSort;
use App\Services\Catalog\CatalogFilters;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Sort/filter parameters of catalog and search pages (SF-12, SF-13). The storefront never answers 422:
 * invalid values are dropped and the page is shown with the remaining ones.
 */
class CatalogFilterRequest extends FormRequest
{
    private const PRICE_PATTERN = '/^\d{1,9}(?:[.,]\d{1,2})?$/';

    private const MAX_BRANDS = 30;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'string', 'in:'.implode(',', array_column(CatalogSort::cases(), 'value'))],
            'price_from' => ['nullable', 'string', 'regex:'.self::PRICE_PATTERN],
            'price_to' => ['nullable', 'string', 'regex:'.self::PRICE_PATTERN],
            'in_stock' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        // Invalid values are ignored in filters(), not reported.
    }

    public function filters(): CatalogFilters
    {
        $validator = $this->getValidatorInstance();
        $validator->fails();
        $invalid = array_map(fn (string $key): string => Str::before($key, '.'), array_keys($validator->errors()->messages()));
        $valid = fn (string $key): bool => ! in_array($key, $invalid, true);

        $from = $valid('price_from') ? $this->price('price_from') : null;
        $to = $valid('price_to') ? $this->price('price_to') : null;

        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new CatalogFilters(
            sort: $valid('sort') ? (CatalogSort::tryFrom((string) $this->query('sort')) ?? CatalogSort::Default) : CatalogSort::Default,
            priceFrom: $from,
            priceTo: $to,
            inStock: $valid('in_stock') && $this->boolean('in_stock'),
            brands: $this->stringList('brands'),
            flags: array_values(array_intersect(CatalogFilters::FLAGS, $this->stringList('flags'))),
            page: max(1, (int) $this->query('page', 1)),
        );
    }

    /**
     * The search phrase, trimmed (empty string when absent or invalid).
     */
    public function searchTerm(): string
    {
        $term = $this->query('q');

        return is_string($term) ? trim(mb_substr($term, 0, 100)) : '';
    }

    private function price(string $key): ?int
    {
        $value = $this->query($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return Money::fromRubles($value);
    }

    /**
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $raw = $this->query($key);

        if (! is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '' && mb_strlen($item) <= 255) {
                $items[] = trim($item);
            }
        }

        return array_slice(array_values(array_unique($items)), 0, self::MAX_BRANDS);
    }
}
