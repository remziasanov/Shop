<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Result of {@see PriceTierService::validate()}: the normalized rows (ascending by min_qty, price in kopecks), errors
 * (the rows cannot be saved) and warnings (they can, GP-14).
 */
final readonly class TierValidation
{
    /**
     * @param  list<array{min_qty: int, price: int}>  $rows
     * @param  list<TierIssue>  $errors
     * @param  list<TierIssue>  $warnings
     */
    public function __construct(public array $rows, public array $errors, public array $warnings) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return list<string>
     */
    public function errorMessages(): array
    {
        return array_map(fn (TierIssue $issue): string => $issue->message, $this->errors);
    }
}
