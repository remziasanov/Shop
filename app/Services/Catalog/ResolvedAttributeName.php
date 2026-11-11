<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Attribute;

/**
 * Outcome of {@see AttributeNameResolver::resolve()} for an import header or a JSON characteristic name (FL-16).
 */
final class ResolvedAttributeName
{
    /** The header matched the signature "name, unit" of an attribute. */
    public const RULE_SIGNATURE = 1;

    /** The header equals the name of an attribute (as in v1: «Х: Размер, см» => «Размер, см»). */
    public const RULE_FULL_NAME = 2;

    /** Nothing found; the last part after «, » is a unit. */
    public const RULE_PARSED_UNIT = 3;

    /** Nothing found and no unit in the header: the full header becomes the name. */
    public const RULE_NEW_FULL = 0;

    /**
     * @param  Attribute|null  $attribute  the existing attribute (rules 1 and 2), null when it has to be created
     * @param  string  $name  name for a new attribute: the part before the unit (rule 3) or the full header
     * @param  string|null  $unit  parsed unit (rule 3 only)
     */
    public function __construct(
        public readonly int $rule,
        public readonly ?Attribute $attribute,
        public readonly string $header,
        public readonly string $name,
        public readonly ?string $unit,
    ) {}

    public function exists(): bool
    {
        return $this->attribute !== null;
    }
}
