<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Models\ProductVariant;

/**
 * Everything the product page needs about the variants of a card (VR-09…VR-11, VR-28): the axes, the compact variant
 * records for the Alpine picker, the preselected variant and the schema.org summary.
 */
final readonly class VariantPickerData
{
    /** Bits of the `c` field of a variant record: which buttons are possible. */
    public const CAN_CART = 1;

    public const CAN_RESERVE = 2;

    public const CAN_INQUIRE = 4;

    public const OUT = 8;

    /**
     * @param  list<array{id: int, name: string, display: string, values: list<array{id: int, text: string, hex: string|null, image: string|null}>}>  $axes  only values used by an active variant
     * @param  list<array<string, mixed>>  $variants  compact records: id, sku, bc, v (value ids in axis order), p, o, d, s, st, c, m, bo
     * @param  int  $selectedId  the variant the page opens with
     * @param  bool  $requested  the selection comes from `?variant=` (schema.org Offer of that variant, VR-28)
     * @param  list<array{price: string, available: bool, backorder: bool, sku: string}>  $offers  for the schema.org summary, in display order
     * @param  ProductVariant  $selected  the model of the preselected variant
     */
    public function __construct(
        public array $axes,
        public array $variants,
        public int $selectedId,
        public bool $requested,
        public array $offers,
        public ?int $colorTypeId,
        public ProductVariant $selected,
    ) {}

    /**
     * The record of a variant.
     *
     * @return array<string, mixed>|null
     */
    public function variant(int $id): ?array
    {
        foreach ($this->variants as $variant) {
            if ($variant['id'] === $id) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Payload of the picker as JSON for a `<script type="application/json">` (no requests on selection, VR-09).
     */
    public function json(): string
    {
        return (string) json_encode([
            'axes' => $this->axes,
            'variants' => $this->variants,
            'selected' => $this->selectedId,
            'colorType' => $this->colorTypeId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    }
}
