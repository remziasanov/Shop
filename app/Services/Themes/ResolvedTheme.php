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
     * @param  array{radius: int, buttonRadius: int, borderWidth: int, surface: string, shadowColor?: string, shadowTint?: string, pressColor?: string}  $shape
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

    /**
     * The same theme with other block variants (the temporary service theme of stage 21 only).
     *
     * @param  array<string, string>  $blocks
     */
    public function withBlocks(array $blocks): self
    {
        return new self($this->id, $this->name, $this->colors, $this->shape, $this->fonts, $blocks, $this->extras, $this->overrides, $this->warnings);
    }

    /**
     * Attributes for the `<html>` tag (TH-14): the surface style and the switches that the surface CSS keys on. Only
     * validated values; the border-width switch is printed only when it differs from the 1 px of the default theme.
     */
    public function htmlAttributes(): string
    {
        $attrs = ['data-surface="'.$this->shape['surface'].'"'];

        if ($this->shape['borderWidth'] !== 1) {
            $attrs[] = 'data-bw="'.$this->shape['borderWidth'].'"';
        }

        $shape = $this->extras['tileShape'] ?? null;

        if (in_array($shape, ['arch', 'circle'], true)) {
            $attrs[] = 'data-tile-shape="'.$shape.'"';
        }

        if (($this->extras['dashedFrames'] ?? false) === true) {
            $attrs[] = 'data-dashed';
        }

        if (($this->extras['cropMarks'] ?? false) === true) {
            $attrs[] = 'data-crop-marks';
        }

        if (isset($this->extras['backgroundImage'])) {
            $attrs[] = 'data-bg-image';
        }

        return implode(' ', $attrs);
    }
}
