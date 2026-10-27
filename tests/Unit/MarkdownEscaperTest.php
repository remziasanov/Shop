<?php

declare(strict_types=1);

use App\Support\MarkdownEscaper;
use Illuminate\Mail\Markdown;

function mdHtml(string $text): string
{
    return Markdown::parse($text)->toHtml();
}

it('escapes Markdown syntax characters and list markers', function (): void {
    expect(MarkdownEscaper::inline('a*b_c`d[e](f)#g+h-i.j!k|l~m'))->toBe('a\*b\_c\`d\[e\]\(f\)\#g\+h\-i\.j\!k\|l\~m')
        ->and(MarkdownEscaper::inline('1. first'))->toBe('1\. first')
        ->and(MarkdownEscaper::inline('back\\slash'))->toBe('back\\\\slash')
        ->and(MarkdownEscaper::inline('<b>x</b> & "q"'))->toBe('&lt;b&gt;x&lt;/b&gt; &amp; &quot;q&quot;')
        ->and(MarkdownEscaper::inline(null))->toBe('');
});

it('turns newlines into hard line breaks and drops empty lines', function (): void {
    expect(MarkdownEscaper::escape("one\r\n\r\n\ntwo\nthree"))->toBe("one  \ntwo  \nthree")
        ->and(MarkdownEscaper::inline("one\n\ntwo"))->toBe('one two');
});

it('renders hostile text as plain text', function (string $input): void {
    $html = mdHtml('Comment: '.MarkdownEscaper::escape($input));

    expect($html)->not->toContain('<a ')
        ->and($html)->not->toContain('<h1')
        ->and($html)->not->toContain('<pre')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<ul')
        ->and($html)->not->toContain('<ol')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toContain('<table');
})->with([
    '[Pay here](https://evil.example)',
    "# Header\n```code```\n<script>alert(1)</script>",
    "ok\n\n[Подтвердите](https://evil.example/login)\n\n    code block",
    'https://evil.example/login and www.evil.example and evil@example.com',
    "1. one\n2. two\n- three\n* four\n> quote\n---\n===",
    '![img](https://evil.example/x.png) <https://evil.example>',
    "| a | b |\n|---|---|\n| c | d |",
]);
