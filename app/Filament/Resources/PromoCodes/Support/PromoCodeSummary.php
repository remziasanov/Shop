<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Support;

use App\Enums\PromoScope;
use App\Enums\PromoTargetType;
use App\Enums\PromoType;
use App\Models\PromoCode;
use App\Services\Catalog\CategoryTree;
use App\Support\Money;

/**
 * Short descriptions of a code for the admin table: «10 %», «500 ₽», «Категории: Обувь (+ подкатегории)», «1 / 100».
 * Expects `targets` to be eager loaded.
 */
final class PromoCodeSummary
{
    private const MAX_LISTED = 3;

    public static function discount(PromoCode $code): string
    {
        return match ($code->type) {
            PromoType::Percent => __('promo_admin.codes.summary.percent', ['value' => str_replace('.', ',', self::plain((string) $code->value))]),
            PromoType::Fixed => Money::format(Money::fromRubles((string) $code->value)),
            PromoType::FreeDelivery => __('promo_admin.codes.summary.free_delivery'),
        };
    }

    public static function scope(PromoCode $code, CategoryTree $categories): string
    {
        $label = $code->scope->label();

        switch ($code->scope) {
            case PromoScope::All:
                return $label;

            case PromoScope::Categories:
                $names = [];

                foreach ($code->targets->where('target_type', PromoTargetType::Category) as $target) {
                    $names[] = $categories->node((int) $target->target_id)->name ?? '#'.$target->target_id;
                }

                $text = $label.': '.self::list($names);

                return $code->include_subcategories ? $text.' '.__('promo_admin.codes.summary.with_subcategories') : $text;

            case PromoScope::Products:
                return $label.': '.__('promo_admin.codes.summary.count_products', ['count' => $code->targets->where('target_type', PromoTargetType::Product)->count()]);

            case PromoScope::Brands:
                return $label.': '.self::list($code->targets->where('target_type', PromoTargetType::Brand)->pluck('target_value')->map(fn ($v): string => (string) $v)->all());
        }
    }

    public static function period(PromoCode $code): string
    {
        $from = $code->starts_at?->format('d.m.Y H:i');
        $to = $code->ends_at?->format('d.m.Y H:i');

        return match (true) {
            $from !== null && $to !== null => $from.' — '.$to,
            $from !== null => __('promo_admin.codes.summary.since', ['date' => $from]),
            $to !== null => __('promo_admin.codes.summary.until', ['date' => $to]),
            default => __('promo_admin.codes.summary.forever'),
        };
    }

    public static function usage(PromoCode $code): string
    {
        return $code->usage_count.' / '.($code->usage_limit ?? __('promo_admin.codes.summary.unlimited'));
    }

    /**
     * @param  list<string>  $names
     */
    private static function list(array $names): string
    {
        $shown = array_slice($names, 0, self::MAX_LISTED);
        $rest = count($names) - count($shown);

        return implode(', ', $shown).($rest > 0 ? ' +'.$rest : '');
    }

    private static function plain(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
