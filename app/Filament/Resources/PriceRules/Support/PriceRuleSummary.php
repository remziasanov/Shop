<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceRules\Support;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Models\PriceRule;
use App\Providers\AppServiceProvider;
use App\Services\Catalog\CategoryTree;
use App\Support\Money;

/**
 * Short human readable descriptions of a rule for the admin table: «Скидка 10 %», «Наценка 500 ₽»,
 * «Категории: Мебель, Свет (+ подкатегории)». Expects `targets` to be eager loaded.
 */
final class PriceRuleSummary
{
    private const MAX_LISTED = 3;

    public static function action(PriceRuleAction $action, string $value): string
    {
        return __('admin_pricing.rules.summary.'.$action->value, ['value' => self::value($action, $value)]);
    }

    /**
     * "10", "12,5" followed by " %" or "500 ₽".
     */
    public static function value(PriceRuleAction $action, string $value): string
    {
        if ($action->isPercent()) {
            $number = str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;

            return str_replace('.', ',', $number).Money::CURRENCY_SEPARATOR.'%';
        }

        return Money::format(Money::fromRubles($value));
    }

    public static function scope(PriceRule $rule, CategoryTree $categories): string
    {
        $label = $rule->scope->label();

        switch ($rule->scope) {
            case PriceRuleScope::All:
                return $label;

            case PriceRuleScope::Categories:
                $names = [];
                foreach ($rule->categoryIds() as $id) {
                    $names[] = $categories->node($id)->name ?? '#'.$id;
                }

                $text = $label.': '.self::list($names);

                return $rule->include_subcategories ? $text.' '.__('admin_pricing.rules.with_subcategories') : $text;

            case PriceRuleScope::Products:
                return $label.': '.__('admin_pricing.rules.products_count', ['count' => count($rule->productIds())]);

            case PriceRuleScope::Brands:
                return $label.': '.self::list($rule->brands());

            case PriceRuleScope::Flags:
                return $label.': '.self::list(array_map(fn ($flag): string => $flag->label(), $rule->flags()));
        }
    }

    public static function period(PriceRule $rule): string
    {
        if ($rule->starts_at === null && $rule->ends_at === null) {
            return __('admin_pricing.rules.unlimited');
        }

        $format = fn ($date): string => $date->format(AppServiceProvider::DATE_TIME_FORMAT);

        return trim(
            ($rule->starts_at !== null ? __('admin_pricing.rules.period_from', ['date' => $format($rule->starts_at)]) : '')
            .' '
            .($rule->ends_at !== null ? __('admin_pricing.rules.period_to', ['date' => $format($rule->ends_at)]) : ''),
        );
    }

    /**
     * @param  list<string>  $items
     */
    private static function list(array $items): string
    {
        $shown = array_slice($items, 0, self::MAX_LISTED);
        $rest = count($items) - count($shown);

        return implode(', ', $shown).($rest > 0 ? ' '.__('admin_pricing.rules.and_more', ['count' => $rest]) : '');
    }
}
