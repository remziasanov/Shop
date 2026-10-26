<?php

declare(strict_types=1);

namespace App\Services\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes HTML from the rich text editor before output (NF-10): a whitelist of formatting
 * elements, safe link/media schemes, no scripts, styles or event handlers.
 *
 * Not applied to the admin-only "HTML" block of the home page.
 */
final class HtmlSanitizer
{
    private readonly SymfonyHtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->withMaxInputLength(2_000_000)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withAttributeSanitizer(new TargetAttributeSanitizer)
            ->forceAttribute('a', 'rel', 'noopener noreferrer');

        foreach (['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'form', 'svg', 'math'] as $element) {
            $config = $config->dropElement($element);
        }

        foreach ([
            'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote',
            'span', 'hr', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'sub', 'sup',
        ] as $element) {
            $config = $config->allowElement($element);
        }

        $config = $config
            ->allowElement('a', ['href', 'target', 'rel', 'title'])
            ->allowElement('img', ['src', 'alt', 'width', 'height', 'title'])
            ->allowElement('th', ['colspan', 'rowspan', 'scope'])
            ->allowElement('td', ['colspan', 'rowspan']);

        $this->sanitizer = new SymfonyHtmlSanitizer($config);
    }

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return $this->sanitizer->sanitize($html);
    }
}
