<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Support\MarkdownEscaper;
use Symfony\Component\Mime\Email;

/**
 * Customer text is backslash-escaped for the Markdown (HTML) part of a mail; the plain-text part must show the
 * original characters. Call from the constructor of a Markdown mailable.
 */
trait StripsMarkdownEscapes
{
    protected function stripMarkdownEscapesFromTextPart(): void
    {
        $this->withSymfonyMessage(function (Email $email): void {
            $text = $email->getTextBody();

            if (is_string($text) && $text !== '') {
                $email->text(MarkdownEscaper::unescape($text));
            }
        });
    }
}
