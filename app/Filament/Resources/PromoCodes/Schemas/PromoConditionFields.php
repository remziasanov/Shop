<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodes\Schemas;

use App\Enums\PromoScope;
use App\Enums\PromoType;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\PromoCode;
use App\Rules\RublesAmount;
use App\Services\Catalog\CategoryTree;
use App\Services\Promo\PromoCodeSaver;
use App\Services\Search\SearchNormalizer;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * The fields that describe what a promo code does and when (PM-02…PM-06). Shared by the code form and by the template of
 * a code batch (PM-15), so both use the same state keys (see {@see PromoCodeSaver}).
 */
final class PromoConditionFields
{
    /**
     * Type + value, scope + targets, conditions, groups, flags.
     *
     * @param  bool  $withLimits  the total and per customer limits (not part of a batch template: batch codes are one-time)
     * @return list<Section>
     */
    public static function sections(bool $withLimits = true): array
    {
        return [
            self::discount(),
            self::scope(),
            self::conditions($withLimits),
            self::groups(),
            self::flags(),
        ];
    }

    public static function discount(): Section
    {
        return Section::make(__('promo_admin.codes.sections.discount'))
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('type')
                        ->label(__('promo_admin.codes.fields.type'))
                        ->options(PromoType::options())
                        ->default(PromoType::Percent->value)
                        ->required()
                        ->native(false)
                        ->live(),
                    TextInput::make('value')
                        ->label(__('promo_admin.codes.fields.value'))
                        ->helperText(fn (Get $get): string => match (PromoType::tryFrom((string) $get('type'))) {
                            PromoType::Percent => __('promo_admin.codes.fields.value_percent'),
                            PromoType::Fixed => __('promo_admin.codes.fields.value_fixed'),
                            default => '',
                        })
                        ->suffix(fn (Get $get): string => PromoType::tryFrom((string) $get('type')) === PromoType::Fixed ? Money::CURRENCY_SIGN : '%')
                        ->inputMode('decimal')
                        ->maxLength(20)
                        ->required(fn (Get $get): bool => PromoType::tryFrom((string) $get('type'))?->hasValue() === true)
                        ->visible(fn (Get $get): bool => PromoType::tryFrom((string) $get('type'))?->hasValue() === true)
                        ->rule(fn (Get $get): Closure => self::valueRule($get('type'))),
                ]),
            ]);
    }

    public static function scope(): Section
    {
        return Section::make(__('promo_admin.codes.sections.scope'))
            ->schema([
                Select::make('scope')
                    ->label(__('promo_admin.codes.fields.scope'))
                    ->options(PromoScope::options())
                    ->default(PromoScope::All->value)
                    ->required()
                    ->native(false)
                    ->live(),
                Select::make('category_ids')
                    ->label(__('promo_admin.codes.fields.categories'))
                    ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->visible(fn (Get $get): bool => $get('scope') === PromoScope::Categories->value),
                Toggle::make('include_subcategories')
                    ->label(__('promo_admin.codes.fields.include_subcategories'))
                    ->default(true)
                    ->visible(fn (Get $get): bool => $get('scope') === PromoScope::Categories->value),
                Select::make('product_ids')
                    ->label(__('promo_admin.codes.fields.products'))
                    ->multiple()
                    ->searchable()
                    ->native(false)
                    ->required()
                    ->getSearchResultsUsing(function (string $search): array {
                        $like = '%'.app(SearchNormalizer::class)->escapeLike($search).'%';

                        return self::productOptions(
                            Product::query()
                                ->where(fn ($q) => $q->whereRaw("name like ? escape '!'", [$like])->orWhereRaw("sku like ? escape '!'", [$like]))
                                ->orderBy('name')
                                ->limit(50)
                                ->get(['id', 'name', 'sku'])
                                ->all(),
                        );
                    })
                    ->getOptionLabelsUsing(fn (array $values): array => self::productOptions(
                        Product::withTrashed()->whereIn('id', $values)->get(['id', 'name', 'sku'])->all(),
                    ))
                    ->visible(fn (Get $get): bool => $get('scope') === PromoScope::Products->value),
                TagsInput::make('brands')
                    ->label(__('promo_admin.codes.fields.brands'))
                    ->helperText(__('promo_admin.codes.fields.brands_hint'))
                    ->suggestions(fn (): array => self::brands())
                    ->required()
                    ->visible(fn (Get $get): bool => $get('scope') === PromoScope::Brands->value),
            ]);
    }

    public static function conditions(bool $withLimits): Section
    {
        $fields = [
            TextInput::make('min_order_amount')
                ->label(__('promo_admin.codes.fields.min_order_amount'))
                ->helperText(__('promo_admin.codes.fields.min_order_amount_hint'))
                ->inputMode('decimal')
                ->suffix(Money::CURRENCY_SIGN)
                ->rule(new RublesAmount),
            DateTimePicker::make('starts_at')
                ->label(__('promo_admin.codes.fields.starts_at'))
                ->seconds(false),
            DateTimePicker::make('ends_at')
                ->label(__('promo_admin.codes.fields.ends_at'))
                ->seconds(false)
                ->rules([fn (Get $get): Closure => self::endsAfterStarts($get('starts_at'))]),
        ];

        if ($withLimits) {
            $fields[] = TextInput::make('usage_limit')
                ->label(__('promo_admin.codes.fields.usage_limit'))
                ->helperText(__('promo_admin.codes.fields.limit_hint'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(1_000_000_000);
            $fields[] = TextInput::make('per_customer_limit')
                ->label(__('promo_admin.codes.fields.per_customer_limit'))
                ->helperText(__('promo_admin.codes.fields.limit_hint'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(1_000_000_000);
        }

        $fields[] = Toggle::make('only_registered')
            ->label(__('promo_admin.codes.fields.only_registered'))
            ->helperText(__('promo_admin.codes.fields.only_registered_hint'))
            ->default(false);

        return Section::make(__('promo_admin.codes.sections.conditions'))
            ->columns(['default' => 1, 'md' => 2])
            ->schema($fields);
    }

    public static function groups(): Section
    {
        return Section::make(__('promo_admin.codes.sections.groups'))
            ->schema([
                CheckboxList::make('group_ids')
                    ->label(__('promo_admin.codes.fields.groups'))
                    ->helperText(__('promo_admin.codes.fields.groups_hint'))
                    ->options(fn (): array => CustomerGroup::query()->ordered()->pluck('name', 'id')->all())
                    ->default(fn (): array => PromoCode::defaultGroupIds())
                    ->columns(['default' => 1, 'md' => 3])
                    ->required()
                    ->minItems(1)
                    ->validationMessages(['required' => __('promo_admin.codes.validation.groups_required'), 'min' => __('promo_admin.codes.validation.groups_required')]),
            ]);
    }

    public static function flags(): Section
    {
        return Section::make(__('promo_admin.codes.sections.flags'))
            ->schema([
                Toggle::make('applies_to_discounted')
                    ->label(__('promo_admin.codes.fields.applies_to_discounted'))
                    ->default(false)
                    ->live(),
                Toggle::make('stackable_with_promotions')
                    ->label(__('promo_admin.codes.fields.stackable_with_promotions'))
                    ->helperText(__('promo_admin.codes.fields.stackable_hint'))
                    ->default(false)
                    ->visible(fn (Get $get): bool => (bool) $get('applies_to_discounted')),
            ]);
    }

    /**
     * PM-02: percent 1–100, fixed above 0, no value for free delivery.
     */
    private static function valueRule(mixed $type): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($type): void {
            $problem = app(PromoCodeSaver::class)->valueProblem(is_string($type) ? $type : null, $value);

            if ($problem !== null) {
                $fail(__('promo_admin.codes.validation.'.$problem));
            }
        };
    }

    private static function endsAfterStarts(mixed $startsAt): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($startsAt): void {
            if (! filled($startsAt) || ! filled($value)) {
                return;
            }

            $start = strtotime((string) ($startsAt instanceof \DateTimeInterface ? $startsAt->format('c') : $startsAt));
            $end = strtotime((string) ($value instanceof \DateTimeInterface ? $value->format('c') : $value));

            if ($start !== false && $end !== false && $end <= $start) {
                $fail(__('promo_admin.codes.validation.ends_after_starts'));
            }
        };
    }

    /**
     * @param  list<Product>  $products
     * @return array<int, string>
     */
    private static function productOptions(array $products): array
    {
        $options = [];

        foreach ($products as $product) {
            $options[$product->id] = $product->name.' ('.$product->sku.')';
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    private static function brands(): array
    {
        return Product::query()
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->limit(500)
            ->pluck('brand')
            ->all();
    }
}
