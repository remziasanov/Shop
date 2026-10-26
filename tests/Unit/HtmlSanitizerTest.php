<?php

declare(strict_types=1);

use App\Services\Content\HtmlSanitizer;

beforeEach(function (): void {
    $this->sanitizer = new HtmlSanitizer;
});

it('keeps safe formatting', function (): void {
    $html = '<p>Текст <strong>жирный</strong> и <em>курсив</em></p><ul><li>раз</li></ul><h2>Заголовок</h2>';

    expect($this->sanitizer->sanitize($html))->toBe($html);
});

it('drops scripts, styles and event handlers', function (): void {
    $out = $this->sanitizer->sanitize('<p onclick="x()">Hi</p><script>alert(1)</script><style>p{}</style><img src="https://e.com/a.jpg" onerror="alert(1)">');

    expect($out)->not->toContain('script')->not->toContain('alert')->not->toContain('onclick')
        ->not->toContain('onerror')->not->toContain('<style')->toContain('<img src="https://e.com/a.jpg"');
});

it('removes javascript links but keeps allowed schemes', function (): void {
    $out = $this->sanitizer->sanitize('<a href="javascript:alert(1)">bad</a> <a href="https://example.com">ok</a> <a href="mailto:a@b.ru">m</a> <a href="tel:+79990000000">t</a> <a href="data:text/html;base64,AAAA">d</a>');

    expect($out)->not->toContain('javascript')->not->toContain('data:')
        ->toContain('href="https://example.com"')->toContain('href="mailto:a&#64;b.ru"')->toContain('href="tel:&#43;79990000000"');
});

it('forces rel on links and limits target values', function (): void {
    $out = $this->sanitizer->sanitize('<a href="/page" target="_blank">a</a><a href="/x" target="evil">b</a>');

    expect($out)->toContain('target="_blank"')->toContain('rel="noopener noreferrer"')->not->toContain('evil');
});

it('allows tables and relative images, rejects non-http image schemes', function (): void {
    $out = $this->sanitizer->sanitize('<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table><img src="/storage/a.jpg" alt="x" width="10" height="5"><img src="javascript:alert(1)">');

    expect($out)->toContain('<table>')->toContain('<td>1</td>')->toContain('src="/storage/a.jpg"')->not->toContain('javascript');
});

it('handles null, empty and long input', function (): void {
    expect($this->sanitizer->sanitize(null))->toBe('')
        ->and($this->sanitizer->sanitize('  '))->toBe('')
        ->and($this->sanitizer->sanitize('<p>'.str_repeat('а', 50000).'</p>'))->toContain(str_repeat('а', 50000));
});
