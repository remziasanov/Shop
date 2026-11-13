<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Support;

use App\Enums\PromoLinkTargetType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoLink;
use App\Services\Variants\VariantLabel;

/**
 * Where a link leads, in words, for the admin table and card: «Товар: Куртка», «Категория: Обувь», «/pages/delivery».
 */
final class PromoLinkSummary
{
    public static function target(PromoLink $link): string
    {
        $label = $link->target_type->label();

        return match ($link->target_type) {
            PromoLinkTargetType::Product => $label.': '.self::product($link),
            PromoLinkTargetType::Variant => $label.': '.self::product($link).self::variant($link),
            PromoLinkTargetType::Category => $label.': '.(Category::query()->whereKey($link->target_id)->value('name') ?? '#'.$link->target_id),
            PromoLinkTargetType::CatalogFilter, PromoLinkTargetType::Url => (string) $link->target_url,
        };
    }

    public static function period(PromoLink $link): string
    {
        $from = $link->starts_at?->format('d.m.Y H:i');
        $to = $link->ends_at?->format('d.m.Y H:i');

        return match (true) {
            $from !== null && $to !== null => $from.' — '.$to,
            $from !== null => __('promo_admin.codes.summary.since', ['date' => $from]),
            $to !== null => __('promo_admin.codes.summary.until', ['date' => $to]),
            default => __('promo_admin.codes.summary.forever'),
        };
    }

    private static function product(PromoLink $link): string
    {
        return (string) (Product::withTrashed()->whereKey($link->target_id)->value('name') ?? '#'.$link->target_id);
    }

    private static function variant(PromoLink $link): string
    {
        $variant = ProductVariant::withTrashed()->whereKey($link->variant_id)->first();

        if ($variant === null) {
            return '';
        }

        $label = app(VariantLabel::class)->for($variant);

        return ' ('.($label === '' ? $variant->sku : $label).')';
    }
}
