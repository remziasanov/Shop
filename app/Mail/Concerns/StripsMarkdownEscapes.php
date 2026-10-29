<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Support\MarkdownEscaper;
use Symfony\Component\Mime\Email;

/**
 * Customer text is backslash-escaped for the Markdown (HTML) part of a mail; the plain-text part must show the
 * original characters. Call from the constructor of a Markdown mailable. A queued mailable cannot hold the closure
 * when it is serialized, so it calls this from `content()` instead (that runs in the worker, right before the send).
 */
trait StripsMarkdownEscapes
{
    private bool $markdownEscapesStripRegistered = false;

    protected function stripMarkdownEscapesFromTextPart(): void
    {
        if ($this->markdownEscapesStripRegistered) {
            return;
        }

        $this->markdownEscapesStripRegistered = true;

        $this->withSymfonyMessage(function (Email $email): void {
            $text = $email->getTextBody();

            if (is_string($text) && $text !== '') {
                $email->text(MarkdownEscaper::unescape($text));
            }
        });
    }
}
