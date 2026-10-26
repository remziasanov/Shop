<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportField;
use App\Models\Import;

/**
 * "The last mapping is remembered" (IM-03).
 *
 * The mapping is saved on the import itself (`imports.mapping`); the headers it was made for are kept in
 * `imports.analysis['headers']`. {@see suggest()} looks at the most recent imports with a saved mapping and
 * reuses assignments for columns whose normalized header is identical; every other column falls back to
 * {@see ColumnMapper::autoMap()}.
 */
final class MappingMemory
{
    /** How many recent imports are examined. */
    private const LOOKBACK = 20;

    /** Minimum share of the current headers that must be known to the remembered import. */
    private const MIN_OVERLAP = 0.5;

    public function __construct(private readonly ColumnMapper $mapper) {}

    /**
     * Save the user's final mapping (and its headers) on the import.
     *
     * @param  list<string|null>  $headers
     * @param  array<int, string|null>  $mapping
     */
    public function remember(Import $import, array $headers, array $mapping): void
    {
        $analysis = $import->analysis ?? [];
        $analysis['headers'] = $headers;

        $import->forceFill([
            'mapping' => $this->mapper->dedupe($mapping, $headers)->mapping,
            'analysis' => $analysis,
        ])->save();
    }

    /**
     * Suggested mapping for new headers.
     *
     * @param  list<string|null>  $headers
     * @return array<int, string|null>
     */
    public function suggest(array $headers, ?int $excludeImportId = null): array
    {
        $auto = $this->mapper->autoMap($headers);
        $current = $this->normalizeAll($headers);
        $known = array_filter($current, fn (string $key): bool => $key !== '');

        if ($known === []) {
            return $auto;
        }

        $candidates = Import::query()
            ->whereNotNull('mapping')
            ->when($excludeImportId !== null, fn ($query) => $query->where('id', '!=', $excludeImportId))
            ->orderByDesc('id')
            ->limit(self::LOOKBACK)
            ->get(['id', 'mapping', 'analysis']);

        foreach ($candidates as $candidate) {
            $savedHeaders = $candidate->analysis['headers'] ?? null;
            $savedMapping = $candidate->mapping;

            if (! is_array($savedHeaders) || ! is_array($savedMapping)) {
                continue;
            }

            $saved = [];

            foreach ($this->normalizeAll($savedHeaders) as $index => $key) {
                if ($key !== '' && ! array_key_exists($key, $saved)) {
                    $saved[$key] = $savedMapping[$index] ?? null;
                }
            }

            $shared = count(array_filter($known, fn (string $key): bool => array_key_exists($key, $saved)));

            if ($shared === 0 || $shared / count($known) < self::MIN_OVERLAP) {
                continue;
            }

            $merged = $auto;

            foreach ($current as $index => $key) {
                if ($key !== '' && array_key_exists($key, $saved) && $this->isValidTarget($saved[$key])) {
                    $merged[$index] = $saved[$key];
                }
            }

            return $this->mapper->dedupe($merged, $headers)->mapping;
        }

        return $auto;
    }

    private function isValidTarget(?string $target): bool
    {
        return $target === null || ImportField::tryFrom($target) !== null || ImportField::attributeName($target) !== null;
    }

    /**
     * @param  array<int, mixed>  $headers
     * @return array<int, string>
     */
    private function normalizeAll(array $headers): array
    {
        $result = [];

        foreach (array_values($headers) as $index => $header) {
            $result[$index] = HeaderNormalizer::normalize((string) $header);
        }

        return $result;
    }
}
