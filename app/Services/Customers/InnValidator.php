<?php

declare(strict_types=1);

namespace App\Services\Customers;

/**
 * Checks of Russian company requisites (CA-10, CG-20): INN with check digits (10 digits for a legal entity, 12 for an
 * individual entrepreneur), KPP format NNNNXXNNN, OGRN (13 digits) and OGRNIP (15 digits) with the check digit.
 * Values are compared as typed after trimming: no spaces or separators are allowed inside.
 */
final class InnValidator
{
    private const INN10_WEIGHTS = [2, 4, 10, 3, 5, 9, 4, 6, 8];

    private const INN11_WEIGHTS = [7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

    private const INN12_WEIGHTS = [3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8];

    public function isValidInn(string $inn): bool
    {
        $inn = trim($inn);

        if (preg_match('/^(\d{10}|\d{12})$/', $inn) !== 1 || trim($inn, '0') === '') {
            return false;
        }

        if (strlen($inn) === 10) {
            return $this->checkDigit($inn, self::INN10_WEIGHTS) === (int) $inn[9];
        }

        return $this->checkDigit($inn, self::INN11_WEIGHTS) === (int) $inn[10]
            && $this->checkDigit($inn, self::INN12_WEIGHTS) === (int) $inn[11];
    }

    /** INN of a legal entity (10 digits): only such a company has a KPP. */
    public function isLegalEntityInn(string $inn): bool
    {
        return strlen(trim($inn)) === 10 && $this->isValidInn($inn);
    }

    public function isValidKpp(string $kpp): bool
    {
        return preg_match('/^\d{4}[\dA-Z]{2}\d{3}$/', trim($kpp)) === 1;
    }

    /** OGRN (13 digits, check digit = first 12 mod 11 mod 10) or OGRNIP (15 digits, first 14 mod 13 mod 10). */
    public function isValidOgrn(string $ogrn): bool
    {
        $ogrn = trim($ogrn);

        if (preg_match('/^(\d{13}|\d{15})$/', $ogrn) !== 1) {
            return false;
        }

        $length = strlen($ogrn);
        $divisor = $length === 13 ? 11 : 13;

        return $this->mod(substr($ogrn, 0, $length - 1), $divisor) % 10 === (int) $ogrn[$length - 1];
    }

    /**
     * @param  list<int>  $weights
     */
    private function checkDigit(string $digits, array $weights): int
    {
        $sum = 0;

        foreach ($weights as $i => $weight) {
            $sum += $weight * (int) $digits[$i];
        }

        return $sum % 11 % 10;
    }

    /** Remainder of a long decimal string without integer overflow. */
    private function mod(string $digits, int $divisor): int
    {
        $rest = 0;

        foreach (str_split($digits) as $digit) {
            $rest = ($rest * 10 + (int) $digit) % $divisor;
        }

        return $rest;
    }
}
