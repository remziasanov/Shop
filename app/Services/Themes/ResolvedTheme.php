<?php

declare(strict_types=1);

namespace App\Services\Themes;

/**
 * A theme with the administrator's overrides applied and every value validated (TH-07, TH-09). This is the `$theme`
 * view variable of the storefront; the properties `id`, `blocks`, `shape['surface']` and `extras` are for templates.
 */
final readonly class ResolvedTheme
{
    /**
     * @param  array<string, string>  $colors  twelve #RRGGBB values keyed as in the theme file
     * @param  array{radius: int, buttonRadius: int, borderWidth: int, surface: string}  $shape
     * @param  array{display: array<string, mixed>, body: array<string, mixed>, mono: array<string, mixed>}  $fonts  `family` is a registry name or null (system stack)
     * @param  array<string, string>  $blocks
     * @param  array<string, mixed>  $extras
     * @param  array<string, array<string, string>>  $overrides  sanitized overrides that were applied
     * @param  list<array{pair: string, ratio: float}>  $warnings  contrast pairs below 4.5:1
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $colors,
        public array $shape,
        public array $fonts,
        public array $blocks,
        public array $extras,
        public array $overrides,
        public array $warnings,
    ) {}
}
