<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Makes customer-provided text inert inside Markdown mail templates (NF-10).
 *
 * `{{ }}` only escapes HTML; the text is then parsed as CommonMark/GFM, so `[x](https://evil)` or `# heading`
 * typed by a customer would become a real link or heading in a mail sent from the shop's domain.
 * The result is already HTML-safe and Markdown-safe: print it raw (`@md()` / `@mdi()` directives), never through `{{ }}`.
 */
final class MarkdownEscaper
{
    /** ASCII punctuation that can start or continue Markdown/GFM syntax. `&` is left to the HTML escaping step. */
    private const SPECIAL = '/[\\\\`*_{}\[\]()#+\-.!|><~=@]/';

    /**
     * Multi-line text: newlines become hard line breaks, empty lines are dropped (no new blocks can start).
     */
    public static function escape(?string $value): string
    {
        $lines = preg_split('/\R/u', trim((string) $value)) ?: [];
        $lines = array_values(array_filter(array_map(fn (string $line): string => self::line($line), $lines), fn (string $line): bool => $line !== ''));

        return implode("  \n", $lines);
    }

    /**
     * Single-line text (headings, table cells, inline values): any whitespace run collapses to one space.
     */
    public static function inline(?string $value): string
    {
        return self::line(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private static function line(string $line): string
    {
        $line = trim($line);

        if ($line === '') {
            return '';
        }

        // Entities first (<, >, &, "), so the later backslashes never touch an entity; ' stays as is.
        $line = htmlspecialchars($line, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
        $line = preg_replace(self::SPECIAL, '\\\\$0', $line) ?? $line;

        // Autolink literals (https://…) are a GFM feature: break the scheme separator ("www." and emails are broken by \. and \@).
        return preg_replace('~:(?=//)~', '\\\\:', $line) ?? $line;
    }
}
