<?php

declare(strict_types=1);

/**
 * Text of a dompdf-made PDF (CX-12): inflates the content streams and decodes the strings of the text operators. Dompdf
 * writes subsetted TrueType fonts with the Identity-H encoding where a character is a two-byte Unicode code unit
 * (the font's ToUnicode map is the identity), so a string is decoded as UTF-16BE. Returns the pieces joined with spaces.
 */
function pdfText(string $pdf): string
{
    $text = [];

    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams);

    foreach ($streams[1] as $raw) {
        $content = @gzuncompress($raw);

        if ($content === false || ! str_contains($content, ' Tf')) {
            continue;
        }

        preg_match_all('/\[((?:\((?:\\\\.|[^\\\\)])*\)|[^\]\(])*)\]\s*TJ/s', $content, $arrays);

        foreach ($arrays[1] as $array) {
            preg_match_all('/\(((?:\\\\.|[^\\\\)])*)\)/s', $array, $strings);

            $bytes = '';

            foreach ($strings[1] as $literal) {
                $bytes .= pdfUnescape($literal);
            }

            $text[] = mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
        }
    }

    // Money uses no-break spaces between the thousands and before «₽»: compare as plain spaces.
    return (string) preg_replace('/[\x{00A0}\x{202F}]/u', ' ', implode(' ', $text));
}

function pdfUnescape(string $literal): string
{
    return (string) preg_replace_callback('/\\\\(?:([0-7]{1,3})|(.))/s', function (array $m): string {
        if (($m[1] ?? '') !== '') {
            return chr((int) octdec($m[1]) & 0xFF);
        }

        return match ($m[2]) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'b' => "\x08",
            'f' => "\x0C",
            default => $m[2],
        };
    }, $literal);
}
