<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Outcome of {@see RowValidator::validate()}.
 *
 * `values` holds only the fields of mapped columns, JSON-safe and already normalized:
 *  sku/name/unit/brand/short_description/description/meta_*: trimmed string|null;
 *  category: list<string> path parts (empty list = blank cell);
 *  price/cost_price/old_price: int kopecks|null; stock/sort: int|null;
 *  photos: list<string>; active/featured/new/price_on_request: bool|null;
 *  sales_mode: SalesMode value | 'default' (explicit "inherit") | null (blank cell).
 * A field that failed validation is absent from `values` (the row has errors and is skipped anyway).
 */
final readonly class RowValidationResult
{
    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public const ACTION_SKIP = 'skip';

    public const ACTION_ERROR = 'error';

    /**
     * @param  list<RowIssue>  $errors
     * @param  list<RowIssue>  $warnings
     * @param  array<string, mixed>  $values
     * @param  list<array{name: string, value: string}>  $attributes
     * @param  string  $action  one of the ACTION_* constants
     */
    public function __construct(
        public array $errors,
        public array $warnings,
        public array $values,
        public array $attributes,
        public string $action,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
