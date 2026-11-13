<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportField;
use App\Models\CustomerGroup;

/**
 * Checks the group price columns of a mapping against the customer groups (GP-15): the code is compared without regard
 * to case, a column of an unknown code is ignored and reported (one warning per column).
 */
final class GroupPriceColumns
{
    /** @var array<string, true>|null lower-cased group codes */
    private ?array $codes = null;

    /**
     * @param  array<int, string|null>  $mapping
     * @param  list<string|null>  $headers
     * @return array{mapping: array<int, string|null>, warnings: list<string>}
     */
    public function filter(array $mapping, array $headers): array
    {
        $warnings = [];

        foreach ($mapping as $index => $target) {
            $groupPrice = ImportField::groupPriceTarget($target);

            if ($groupPrice === null || isset($this->codes()[$groupPrice['code']])) {
                continue;
            }

            $mapping[$index] = null;
            $warnings[] = __('admin_group_prices.import.unknown_group', [
                'header' => (string) ($headers[$index] ?? ColumnMapper::fieldLabel((string) $target)),
                'code' => $groupPrice['code'],
            ]);
        }

        return ['mapping' => $mapping, 'warnings' => $warnings];
    }

    /**
     * @return array<string, true>
     */
    private function codes(): array
    {
        if ($this->codes === null) {
            $this->codes = [];

            foreach (CustomerGroup::query()->pluck('code') as $code) {
                $this->codes[mb_strtolower((string) $code, 'UTF-8')] = true;
            }
        }

        return $this->codes;
    }
}
