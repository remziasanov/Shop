<?php

declare(strict_types=1);

namespace App\Services\Variants;

use App\Enums\OptionDisplayType;
use App\Exceptions\VariantException;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\ProductImage;

/**
 * Photo to colour binding of a card (VR-11): a photo may belong to one value of the card's colour axis (an option of
 * display type `color`); a photo without a value is common to every variant.
 */
final class VariantPhotoColors
{
    /**
     * Values a photo of the card can be bound to: the values of its colour axes, option id => text. Empty when the
     * card has no colour axis.
     *
     * @return array<int, string>
     */
    public function choices(Product $product): array
    {
        if (! $product->has_variants) {
            return [];
        }

        $typeIds = $product->options()->where('option_types.display_type', OptionDisplayType::Color->value)->pluck('option_types.id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($typeIds === []) {
            return [];
        }

        $choices = [];

        foreach (OptionValue::query()->whereIn('option_type_id', $typeIds)->orderBy('option_type_id')->orderBy('sort_order')->orderBy('id')->get() as $value) {
            $choices[$value->id] = count($typeIds) > 1 ? OptionType::query()->whereKey($value->option_type_id)->value('name').': '.$value->value : $value->value;
        }

        return $choices;
    }

    /**
     * Binds the photo to a colour value of its card, or makes it common with null.
     *
     * @throws VariantException the value is not a value of a colour axis of the card
     */
    public function assign(ProductImage $image, ?int $optionValueId): ProductImage
    {
        if ($optionValueId !== null) {
            $product = Product::withTrashed()->findOrFail($image->product_id);

            if (! array_key_exists($optionValueId, $this->choices($product))) {
                throw VariantException::photoColorInvalid();
            }
        }

        $image->option_value_id = $optionValueId;
        $image->save();

        return $image;
    }
}
