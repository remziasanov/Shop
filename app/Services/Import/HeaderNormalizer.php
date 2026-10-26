<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Header comparison key (IM-03): lower case, "ё" => "е", every character that is not a letter or a digit
 * removed ("Кол-во", "кол во" and "КОЛВО" are the same header).
 */
final class HeaderNormalizer
{
    public static function normalize(string $header): string
    {
        $header = mb_strtolower($header, 'UTF-8');
        $header = str_replace('ё', 'е', $header);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $header) ?? '';
    }
}
