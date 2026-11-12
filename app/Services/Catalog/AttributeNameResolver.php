<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Attribute;

/**
 * Finds the characteristic for an import column header or a v1 JSON name (FL-16), strictly in this order, so that
 * re-importing files of version 1.0 does not break:
 *  1. an attribute whose signature ("name" or "name, unit") equals the header;
 *  2. an attribute whose name equals the full header;
 *  3. only when nothing is found: the last part after «, » is a unit (suffix up to 12 characters, prefix not empty).
 *
 * Names are compared normalized: case, repeated spaces and ё => е do not matter ({@see normalize()}). The
 * dictionary is read once and kept in memory; call {@see remember()} for attributes created meanwhile.
 */
final class AttributeNameResolver
{
    /** @var array<string, Attribute>|null normalized signature => attribute */
    private ?array $bySignature = null;

    /** @var array<string, Attribute> normalized name => attribute */
    private array $byName = [];

    /**
     * Case, spaces and ё => е insensitive key of a name or a value.
     */
    public static function normalize(string $name): string
    {
        $name = AttributeValueParser::collapse($name);

        return str_replace(['ё', 'Ё'], 'е', mb_strtolower($name, 'UTF-8'));
    }

    public function resolve(string $header): ResolvedAttributeName
    {
        $this->load();

        $header = AttributeValueParser::collapse($header);
        $key = self::normalize($header);

        if (isset($this->bySignature[$key])) {
            return new ResolvedAttributeName(ResolvedAttributeName::RULE_SIGNATURE, $this->bySignature[$key], $header, $header, null);
        }

        if (isset($this->byName[$key])) {
            return new ResolvedAttributeName(ResolvedAttributeName::RULE_FULL_NAME, $this->byName[$key], $header, $header, null);
        }

        $parsed = self::splitUnit($header);

        if ($parsed !== null) {
            return new ResolvedAttributeName(ResolvedAttributeName::RULE_PARSED_UNIT, null, $header, $parsed[0], $parsed[1]);
        }

        return new ResolvedAttributeName(ResolvedAttributeName::RULE_NEW_FULL, null, $header, $header, null);
    }

    /**
     * Exact lookup by the name of an attribute (no unit parsing); null when unknown.
     */
    public function findByName(string $name): ?Attribute
    {
        $this->load();

        return $this->byName[self::normalize($name)] ?? null;
    }

    /**
     * "Размер, см" => ['Размер', 'см']; null when the header has no valid ", unit" tail.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitUnit(string $header): ?array
    {
        $header = AttributeValueParser::collapse($header);
        $position = mb_strrpos($header, ', ');

        if ($position === false) {
            return null;
        }

        $name = trim(mb_substr($header, 0, $position));
        $unit = trim(mb_substr($header, $position + 2));

        if ($name === '' || $unit === '' || mb_strlen($unit) > AttributeValueParser::MAX_UNIT_LENGTH) {
            return null;
        }

        return [$name, $unit];
    }

    /**
     * Register an attribute created or renamed after the dictionary was loaded.
     */
    public function remember(Attribute $attribute): void
    {
        $this->load();

        $this->forget($attribute->id);
        $this->bySignature[self::normalize($attribute->signature())] ??= $attribute;
        $this->byName[self::normalize($attribute->name)] ??= $attribute;
    }

    public function forget(int $attributeId): void
    {
        $this->load();

        $keep = fn (Attribute $attribute): bool => $attribute->id !== $attributeId;
        $this->bySignature = array_filter($this->bySignature ?? [], $keep);
        $this->byName = array_filter($this->byName, $keep);
    }

    /**
     * Drop the in-memory dictionary; the next call reads it again.
     */
    public function refresh(): void
    {
        $this->bySignature = null;
        $this->byName = [];
    }

    private function load(): void
    {
        if ($this->bySignature !== null) {
            return;
        }

        $this->bySignature = [];
        $this->byName = [];

        foreach (Attribute::query()->ordered()->get() as $attribute) {
            // The first attribute in dictionary order wins when two normalize to the same key.
            $this->bySignature[self::normalize($attribute->signature())] ??= $attribute;
            $this->byName[self::normalize($attribute->name)] ??= $attribute;
        }
    }
}
