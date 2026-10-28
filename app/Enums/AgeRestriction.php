<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Age restriction level of a category or product (TZ 16.4, AG-01). "No restriction" is stored as NULL, so only the
 * restricted levels are cases. Stored as varchar(2).
 */
enum AgeRestriction: string
{
    case Age16 = '16';
    case Age18 = '18';

    /**
     * Minimum age of the level.
     */
    public function minAge(): int
    {
        return (int) $this->value;
    }

    /**
     * Badge text: «16+» / «18+».
     */
    public function badge(): string
    {
        return $this->value.'+';
    }

    public function label(): string
    {
        return $this->badge();
    }

    /**
     * Level for a number of years; null when it is not a configured level.
     */
    public static function fromAge(int|string|null $age): ?self
    {
        return $age === null ? null : self::tryFrom((string) $age);
    }

    /**
     * The stricter (higher) of two optional levels.
     */
    public static function max(?self $a, ?self $b): ?self
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->minAge() >= $b->minAge() ? $a : $b;
    }

    /**
     * @return array<int, string> value => label (numeric keys: PHP casts "16" to int), for selects (the "no restriction" option is the placeholder)
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
