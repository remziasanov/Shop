<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Column mapping together with the columns dropped because their target was already taken.
 */
final readonly class MappingResult
{
    /**
     * @param  array<int, string|null>  $mapping  column index => field key, "attr:<Name>" or null (ignored)
     * @param  list<array{column: int, header: string, field: string, first: int}>  $duplicates  columns ignored as
     *                                                                                           repeats (the first column wins); `first` is the winning column index
     */
    public function __construct(
        public array $mapping,
        public array $duplicates = [],
    ) {}

    /**
     * Translated warnings for the mapping step.
     *
     * @param  list<string|null>  $headers
     * @return list<string>
     */
    public function warnings(array $headers): array
    {
        $warnings = [];

        foreach ($this->duplicates as $duplicate) {
            $warnings[] = __('import.mapping.duplicate', [
                'header' => $duplicate['header'],
                'field' => ColumnMapper::fieldLabel($duplicate['field']),
                'first' => (string) ($headers[$duplicate['first']] ?? ''),
            ]);
        }

        return $warnings;
    }
}
