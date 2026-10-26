<?php

declare(strict_types=1);

namespace App\Filament\Resources\PriceRules\Schemas;

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\ProductFlag;
use App\Models\PriceRule;
use App\Models\Product;
use App\Rules\PositiveAmount;
use App\Services\Catalog\CategoryTree;
use App\Services\Pricing\PriceRulePreview;
use App\Services\Pricing\PriceRuleSaver;
use App\Services\Search\SearchNormalizer;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Price rule form (TZ 6.8): the rule fields, dependent target fields per scope and the PR-04 preview.
 * Targets are plain fields (category_ids, product_ids, brands, flags); {@see PriceRuleSaver} stores them.
 */
final class PriceRuleForm
{
    /** Upper bounds of rule values (value is decimal(12,4): at most 8 integer digits). */
    public const MAX_MARKUP_PERCENT = '1000';

    public const MAX_FIXED_AMOUNT = '99999999.99';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin_pricing.rules.sections.rule'))
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('name')
                            ->label(__('admin_pricing.rules.fields.name'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('action')
                            ->label(__('admin_pricing.rules.fields.action'))
                            ->options(PriceRuleAction::options())
                            ->required()
                            ->native(false)
                            ->live(),
                        TextInput::make('value')
                            ->label(__('admin_pricing.rules.fields.value'))
                            ->helperText(fn (Get $get): string => self::valueHint($get('action')))
                            ->suffix(fn (Get $get): string => self::isPercent($get('action')) ? '%' : Money::CURRENCY_SIGN)
                            ->inputMode('decimal')
                            ->required()
                            ->maxLength(20)
                            ->rule(fn (Get $get): PositiveAmount => self::valueRule($get('action')))
                            ->live(onBlur: true),
                        TextInput::make('priority')
                            ->label(__('admin_pricing.rules.fields.priority'))
                            ->helperText(__('admin_pricing.rules.fields.priority_hint'))
                            ->numeric()
                            ->integer()
                            ->minValue(-1_000_000)
                            ->maxValue(1_000_000)
                            ->default(0)
                            ->required(),
                        Toggle::make('is_active')
                            ->label(__('admin_pricing.rules.fields.is_active'))
                            ->default(true),
                        Toggle::make('show_as_discount')
                            ->label(__('admin_pricing.rules.fields.show_as_discount'))
                            ->helperText(__('admin_pricing.rules.fields.show_as_discount_hint'))
                            ->default(false)
                            ->visible(fn (Get $get): bool => PriceRuleAction::tryFrom((string) $get('action'))?->isDiscount() === true),
                        Toggle::make('stop_processing')
                            ->label(__('admin_pricing.rules.fields.stop_processing'))
                            ->helperText(__('admin_pricing.rules.fields.stop_processing_hint'))
                            ->default(false),
                    ]),
                ]),

            Section::make(__('admin_pricing.rules.sections.scope'))
                ->schema([
                    Select::make('scope')
                        ->label(__('admin_pricing.rules.fields.scope'))
                        ->options(PriceRuleScope::options())
                        ->default(PriceRuleScope::All->value)
                        ->required()
                        ->native(false)
                        ->live(),
                    Select::make('category_ids')
                        ->label(__('admin_pricing.rules.fields.categories'))
                        ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                        ->multiple()
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->visible(fn (Get $get): bool => $get('scope') === PriceRuleScope::Categories->value),
                    Toggle::make('include_subcategories')
                        ->label(__('admin_pricing.rules.fields.include_subcategories'))
                        ->default(true)
                        ->visible(fn (Get $get): bool => $get('scope') === PriceRuleScope::Categories->value),
                    Select::make('product_ids')
                        ->label(__('admin_pricing.rules.fields.products'))
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
                        ->visible(fn (Get $get): bool => $get('scope') === PriceRuleScope::Products->value),
                    TagsInput::make('brands')
                        ->label(__('admin_pricing.rules.fields.brands'))
                        ->helperText(__('admin_pricing.rules.fields.brands_hint'))
                        ->suggestions(fn (): array => self::brands())
                        ->required()
                        ->visible(fn (Get $get): bool => $get('scope') === PriceRuleScope::Brands->value),
                    CheckboxList::make('flags')
                        ->label(__('admin_pricing.rules.fields.flags'))
                        ->options(ProductFlag::options())
                        ->required()
                        ->visible(fn (Get $get): bool => $get('scope') === PriceRuleScope::Flags->value),
                ]),

            Section::make(__('admin_pricing.rules.sections.period'))
                ->description(__('admin_pricing.rules.sections.period_hint'))
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        DateTimePicker::make('starts_at')
                            ->label(__('admin_pricing.rules.fields.starts_at'))
                            ->seconds(false),
                        DateTimePicker::make('ends_at')
                            ->label(__('admin_pricing.rules.fields.ends_at'))
                            ->seconds(false)
                            ->rules([fn (Get $get): Closure => self::endsAfterStarts($get('starts_at'))]),
                    ]),
                ]),

            Section::make(__('admin_pricing.preview.heading'))
                ->description(__('admin_pricing.preview.hint'))
                ->hiddenOn('view')
                ->schema([
                    Actions::make([
                        Action::make('preview')
                            ->label(__('admin_pricing.preview.button'))
                            ->icon('heroicon-o-eye')
                            ->color('gray')
                            ->modalHeading(__('admin_pricing.preview.heading'))
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel(__('admin_pricing.preview.close'))
                            ->modalWidth('4xl')
                            ->modalContent(fn (Get $get, ?Model $record) => self::previewView(self::state($get), $record)),
                    ])->key('preview_actions'),
                ]),
        ]);
    }

    /**
     * Number of products and the first 20 with the price before and after the draft rule.
     *
     * @param  array<string, mixed>  $state
     */
    public static function previewView(array $state, ?Model $record): View
    {
        try {
            $draft = app(PriceRuleSaver::class)->toData($state, $record instanceof PriceRule ? $record->id : PHP_INT_MAX);
        } catch (InvalidArgumentException) {
            return view('filament.pricing.preview-table', ['empty' => __('admin_pricing.preview.incomplete')]);
        }

        $preview = app(PriceRulePreview::class)->preview($draft);

        return view('filament.pricing.preview-table', [
            'summary' => __('admin_pricing.preview.count', ['count' => $preview['count']]),
            'empty' => __('admin_pricing.preview.none'),
            'rows' => array_map(fn (array $row): array => [
                'name' => $row['name'],
                'sku' => $row['sku'],
                'before' => Money::format($row['before']),
                'after' => Money::format($row['after']),
            ], $preview['rows']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function state(Get $get): array
    {
        $state = [];

        foreach ([
            'name', 'action', 'value', 'scope', 'include_subcategories', 'priority', 'starts_at', 'ends_at',
            'show_as_discount', 'stop_processing', 'category_ids', 'product_ids', 'brands', 'flags',
        ] as $key) {
            $state[$key] = $get($key);
        }

        return $state;
    }

    private static function isPercent(mixed $action): bool
    {
        return PriceRuleAction::tryFrom((string) $action)?->isPercent() === true;
    }

    private static function valueHint(mixed $action): string
    {
        $case = PriceRuleAction::tryFrom((string) $action);

        return match (true) {
            $case === null => '',
            $case->isPercent() => __('admin_pricing.rules.fields.value_hint_percent'),
            default => __('admin_pricing.rules.fields.value_hint_rubles'),
        };
    }

    private static function valueRule(mixed $action): PositiveAmount
    {
        $case = PriceRuleAction::tryFrom((string) $action);

        return match (true) {
            $case === PriceRuleAction::DiscountPercent => new PositiveAmount(4, '100'),
            $case?->isPercent() === true => new PositiveAmount(4, self::MAX_MARKUP_PERCENT),
            default => new PositiveAmount(2, self::MAX_FIXED_AMOUNT),
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
                $fail('admin_pricing.validation.ends_after_starts')->translate();
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
