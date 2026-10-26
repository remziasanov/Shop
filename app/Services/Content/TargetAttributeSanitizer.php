<?php

declare(strict_types=1);

namespace App\Services\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Keeps `target` on links only for "_blank" and "_self".
 */
final class TargetAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): array
    {
        return ['a'];
    }

    public function getSupportedAttributes(): array
    {
        return ['target'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return in_array($value, ['_blank', '_self'], true) ? $value : null;
    }
}
