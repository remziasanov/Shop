<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\CategorySheetField;
use App\Enums\ImportField;

/**
 * Header => field mapping (IM-03, TZ appendix A).
 *
 * Mapping format (stored in `imports.mapping`): an array indexed by column number, the value is a
 * {@see ImportField} key, "attr:<Name>" for a characteristic column, or null for an ignored column.
 * When two columns resolve to the same target the first one wins, the others are ignored and reported.
 */
final class ColumnMapper
{
    /** Matches «Х: Цвет», «x: Цвет» (Cyrillic or Latin X) and «Характеристика: Цвет». */
    private const ATTRIBUTE_PATTERN = '/^\s*(?:[ХхXx]|характеристика)\s*:\s*(.*?)\s*$/iu';

    /** @var array<string, string>|null normalized synonym => ImportField key */
    private ?array $productLookup = null;

    /** @var array<string, string>|null normalized synonym => CategorySheetField key */
    private ?array $categoryLookup = null;

    /**
     * @param  list<string|null>  $headers
     * @return array<int, string|null>
     */
    public function autoMap(array $headers): array
    {
        return $this->autoMapWithWarnings($headers)->mapping;
    }

    /**
     * @param  list<string|null>  $headers
     */
    public function autoMapWithWarnings(array $headers): MappingResult
    {
        $raw = [];

        foreach ($headers as $index => $header) {
            $raw[$index] = $this->resolveProductHeader((string) $header);
        }

        return $this->dedupe($raw, $headers);
    }

    /**
     * Auto mapping for the «Категории» sheet: column index => {@see CategorySheetField} key or null.
     *
     * @param  list<string|null>  $headers
     * @return array<int, string|null>
     */
    public function autoMapCategories(array $headers): array
    {
        $raw = [];

        foreach ($headers as $index => $header) {
            $raw[$index] = $this->categoryLookup()[HeaderNormalizer::normalize((string) $header)] ?? null;
        }

        return $this->dedupe($raw, $headers)->mapping;
    }

    /**
     * Enforce "one column per target": keep the first column, ignore the repeats. Use it also for
     * mappings edited by the user. Unknown keys are ignored as well.
     *
     * @param  array<int, string|null>  $mapping
     * @param  list<string|null>  $headers
     */
    public function dedupe(array $mapping, array $headers = []): MappingResult
    {
        $result = [];
        $duplicates = [];
        $taken = [];

        ksort($mapping);

        foreach ($mapping as $index => $field) {
            $index = (int) $index;

            if ($field === null || $field === '') {
                $result[$index] = null;

                continue;
            }

            if (isset($taken[$field])) {
                $result[$index] = null;
                $duplicates[] = [
                    'column' => $index,
                    'header' => (string) ($headers[$index] ?? ''),
                    'field' => $field,
                    'first' => $taken[$field],
                ];

                continue;
            }

            $taken[$field] = $index;
            $result[$index] = $field;
        }

        return new MappingResult($result, $duplicates);
    }

    /**
     * Apply a mapping to a raw row.
     *
     * @param  list<string|null>  $row
     * @param  array<int, string|null>  $mapping
     */
    public function mapRow(array $row, array $mapping): MappedRow
    {
        $values = [];
        $attributes = [];

        foreach ($mapping as $index => $target) {
            if ($target === null || $target === '') {
                continue;
            }

            $cell = $row[(int) $index] ?? null;
            $name = ImportField::attributeName($target);

            if ($name !== null) {
                if ($cell !== null && $cell !== '') {
                    $attributes[] = ['name' => $name, 'value' => $cell];
                }

                continue;
            }

            $values[$target] = $cell === '' ? null : $cell;
        }

        return new MappedRow($values, $attributes);
    }

    /**
     * Same as {@see mapRow()} for the «Категории» sheet; returns field key => raw cell text.
     *
     * @param  list<string|null>  $row
     * @param  array<int, string|null>  $mapping
     * @return array<string, string|null>
     */
    public function mapCategoryRow(array $row, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $index => $target) {
            if ($target === null || $target === '') {
                continue;
            }

            $cell = $row[(int) $index] ?? null;
            $values[$target] = $cell === '' ? null : $cell;
        }

        return $values;
    }

    /**
     * Whether a mapping assigns the given field to some column.
     *
     * @param  array<int, string|null>  $mapping
     */
    public function isMapped(array $mapping, ImportField $field): bool
    {
        return in_array($field->value, $mapping, true);
    }

    /**
     * Characteristic name of a header ("Х: Цвет" => "Цвет"), null when it is not a characteristic column.
     */
    public static function attributeNameFromHeader(string $header): ?string
    {
        if (preg_match(self::ATTRIBUTE_PATTERN, $header, $m) === 1 && $m[1] !== '') {
            return $m[1];
        }

        return null;
    }

    /**
     * Human-readable label of a mapping target for messages.
     */
    public static function fieldLabel(string $target): string
    {
        $name = ImportField::attributeName($target);

        if ($name !== null) {
            return __('import.mapping.attribute', ['name' => $name]);
        }

        return ImportField::tryFrom($target)?->label() ?? $target;
    }

    private function resolveProductHeader(string $header): ?string
    {
        $name = self::attributeNameFromHeader($header);

        if ($name !== null) {
            return ImportField::attributeKey($name);
        }

        return $this->productLookup()[HeaderNormalizer::normalize($header)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function productLookup(): array
    {
        return $this->productLookup ??= $this->buildLookup(ImportField::cases());
    }

    /**
     * @return array<string, string>
     */
    private function categoryLookup(): array
    {
        return $this->categoryLookup ??= $this->buildLookup(CategorySheetField::cases());
    }

    /**
     * @param  list<ImportField|CategorySheetField>  $cases
     * @return array<string, string>
     */
    private function buildLookup(array $cases): array
    {
        $lookup = [];

        foreach ($cases as $case) {
            foreach ($case->synonyms() as $synonym) {
                $key = HeaderNormalizer::normalize($synonym);

                if ($key !== '') {
                    // The first definition wins if two fields ever share a synonym.
                    $lookup[$key] ??= (string) $case->value;
                }
            }
        }

        return $lookup;
    }
}
