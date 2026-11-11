<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\AttributeType;

/**
 * Streaming statistics of the values of one characteristic: add every value, then {@see infer()} the type (FL-04).
 * Memory is bounded by the number of distinct values.
 */
final class AttributeStats
{
    /** FL-04: more distinct values than this make a list non-filterable. */
    public const MAX_DISTINCT = 200;

    /** FL-04: longer values make a list non-filterable. */
    public const MAX_VALUE_LENGTH = 100;

    /** FL-04: share of values that must parse as numbers. */
    public const NUMBER_SHARE = 0.95;

    private int $total = 0;

    private int $booleanWords = 0;

    private int $numeric = 0;

    private int $noSuffix = 0;

    /** @var array<string, int> */
    private array $suffixes = [];

    /** @var array<string, string> lower-cased suffix => first seen spelling */
    private array $suffixText = [];

    /** @var array<string, string> normalized key => first seen text, list values after splitting on «;» */
    private array $distinct = [];

    private int $longest = 0;

    /**
     * @param  iterable<string>  $values
     */
    public static function from(iterable $values): self
    {
        $stats = new self;

        foreach ($values as $value) {
            $stats->add($value);
        }

        return $stats;
    }

    public function add(string $value): void
    {
        $value = AttributeValueParser::collapse($value);

        if ($value === '') {
            return;
        }

        $this->total++;

        if (AttributeValueParser::isBooleanWord($value)) {
            $this->booleanWords++;
        }

        $number = AttributeValueParser::parseNumber($value);

        if ($number !== null) {
            $this->numeric++;

            if ($number['suffix'] === '') {
                $this->noSuffix++;
            } else {
                $key = mb_strtolower($number['suffix'], 'UTF-8');
                $this->suffixes[$key] = ($this->suffixes[$key] ?? 0) + 1;
                $this->suffixText[$key] ??= $number['suffix'];
            }
        }

        foreach (AttributeValueParser::splitList($value) as $part) {
            $this->longest = max($this->longest, mb_strlen($part));
            $this->distinct[AttributeNameResolver::normalize($part)] ??= $part;
        }
    }

    public function total(): int
    {
        return $this->total;
    }

    /**
     * Distinct list values (after splitting on «;»), normalized key => first seen text.
     *
     * @return array<string, string>
     */
    public function distinctValues(): array
    {
        return $this->distinct;
    }

    /**
     * @param  string|null  $knownUnit  unit already known from the name (FL-16 rule 3); suffixes equal to it fit
     */
    public function infer(?string $knownUnit = null): AttributeTypeInference
    {
        $distinct = count($this->distinct);

        if ($this->total === 0) {
            return new AttributeTypeInference(AttributeType::List, null, false, 0, 0.0, false, AttributeTypeInference::REASON_DISTINCT);
        }

        if ($this->booleanWords === $this->total) {
            return new AttributeTypeInference(AttributeType::Boolean, null, true, $distinct, 0.0, false);
        }

        [$unit, $fitting] = $this->commonUnit($knownUnit);
        $share = $fitting / $this->total;

        if ($share >= self::NUMBER_SHARE) {
            return new AttributeTypeInference(AttributeType::Number, $unit, true, $distinct, $share, $share < 1.0);
        }

        $disputed = $share >= 0.5 || $this->booleanWords / $this->total >= 0.5;

        if ($distinct > self::MAX_DISTINCT) {
            return new AttributeTypeInference(AttributeType::List, null, false, $distinct, $share, $disputed, AttributeTypeInference::REASON_DISTINCT);
        }

        if ($this->longest > self::MAX_VALUE_LENGTH) {
            return new AttributeTypeInference(AttributeType::List, null, false, $distinct, $share, $disputed, AttributeTypeInference::REASON_LONG);
        }

        return new AttributeTypeInference(AttributeType::List, null, true, $distinct, $share, $disputed);
    }

    /**
     * The unit shared by the numeric values and how many values fit it (values without a suffix always fit).
     *
     * @return array{0: ?string, 1: int}
     */
    private function commonUnit(?string $knownUnit): array
    {
        if ($knownUnit !== null && $knownUnit !== '') {
            $key = mb_strtolower($knownUnit, 'UTF-8');

            return [$knownUnit, $this->noSuffix + ($this->suffixes[$key] ?? 0)];
        }

        if ($this->suffixes === []) {
            return [null, $this->noSuffix];
        }

        arsort($this->suffixes);
        $key = (string) array_key_first($this->suffixes);

        return [$this->suffixText[$key], $this->noSuffix + $this->suffixes[$key]];
    }
}
