<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoLinks\Schemas;

use App\Enums\PromoLinkTargetType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Services\Catalog\CategoryTree;
use App\Services\Promo\PromoLinkResolver;
use App\Services\Promo\PromoLinkSaver;
use App\Services\Search\SearchNormalizer;
use App\Services\Variants\VariantLabel;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Promo link form (PM-21): name, slug, destination with a selector per type, optional promo code, period, UTM, activity.
 * Internal addresses only (PM-22): the `url` and `catalog_filter` values are checked with
 * {@see PromoLinkResolver::isInternalPath()}.
 */
final class PromoLinkForm
{
    public static function configure(Schema $schema): Schema
    {
        $type = fn (Get $get): ?PromoLinkTargetType => PromoLinkTargetType::tryFrom((string) $get('target_type'));

        return $schema->components([
            Section::make(__('promo_admin.links.sections.main'))
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('name')
                            ->label(__('promo_admin.links.fields.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('promo_admin.links.fields.slug'))
                            ->helperText(__('promo_admin.links.fields.slug_hint', ['base' => rtrim((string) config('app.url'), '/')]))
                            ->required()
                            ->maxLength(64)
                            ->rules([fn (?Model $record): Closure => self::slugRule($record instanceof PromoLink ? $record : null)]),
                        Toggle::make('is_active')
                            ->label(__('promo_admin.links.fields.is_active'))
                            ->default(true),
                    ]),
                ]),

            Section::make(__('promo_admin.links.sections.target'))
                ->schema([
                    Select::make('target_type')
                        ->label(__('promo_admin.links.fields.target_type'))
                        ->options(PromoLinkTargetType::options())
                        ->default(PromoLinkTargetType::Product->value)
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('variant_id', null);
                        }),
                    Select::make('product_id')
                        ->label(__('promo_admin.links.fields.product'))
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('variant_id', null))
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
                        ->getOptionLabelUsing(fn (mixed $value): ?string => self::productOptions(
                            Product::withTrashed()->whereKey($value)->get(['id', 'name', 'sku'])->all(),
                        )[(int) $value] ?? null)
                        ->visible(fn (Get $get): bool => in_array($type($get), [PromoLinkTargetType::Product, PromoLinkTargetType::Variant], true)),
                    Select::make('variant_id')
                        ->label(__('promo_admin.links.fields.variant'))
                        ->native(false)
                        ->required()
                        ->options(fn (Get $get): array => self::variantOptions((int) $get('product_id')))
                        ->visible(fn (Get $get): bool => $type($get) === PromoLinkTargetType::Variant),
                    Select::make('category_id')
                        ->label(__('promo_admin.links.fields.category'))
                        ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->visible(fn (Get $get): bool => $type($get) === PromoLinkTargetType::Category),
                    Select::make('filter_category_id')
                        ->label(__('promo_admin.links.fields.filter_category'))
                        ->helperText(__('promo_admin.links.fields.filter_category_hint'))
                        ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                        ->searchable()
                        ->native(false)
                        ->visible(fn (Get $get): bool => $type($get) === PromoLinkTargetType::CatalogFilter),
                    TextInput::make('filter_query')
                        ->label(__('promo_admin.links.fields.filter_query'))
                        ->helperText(__('promo_admin.links.fields.filter_query_hint'))
                        ->maxLength(400)
                        ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (filled($value) && ! PromoLinkSaver::validQuery((string) $value)) {
                                $fail(__('promo_admin.links.validation.filter_query'));
                            }
                        }])
                        ->visible(fn (Get $get): bool => $type($get) === PromoLinkTargetType::CatalogFilter),
                    TextInput::make('target_url')
                        ->label(__('promo_admin.links.fields.target_url'))
                        ->helperText(__('promo_admin.links.fields.target_url_hint'))
                        ->maxLength(PromoLinkResolver::MAX_URL)
                        ->required()
                        ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! PromoLinkResolver::isInternalPath(trim((string) $value))) {
                                $fail(__('promo_admin.links.validation.url_internal'));
                            }
                        }])
                        ->visible(fn (Get $get): bool => $type($get) === PromoLinkTargetType::Url),
                ]),

            Section::make(__('promo_admin.links.sections.code'))
                ->schema([
                    Select::make('promo_code_id')
                        ->label(__('promo_admin.links.fields.promo_code'))
                        ->helperText(__('promo_admin.links.fields.promo_code_hint'))
                        ->searchable()
                        ->native(false)
                        ->getSearchResultsUsing(function (string $search): array {
                            $like = '%'.app(SearchNormalizer::class)->escapeLike($search).'%';

                            return PromoCode::query()
                                ->where(fn ($q) => $q->whereRaw("code like ? escape '!'", [$like])->orWhereRaw("name like ? escape '!'", [$like]))
                                ->orderBy('code')
                                ->limit(50)
                                ->get(['id', 'code', 'name'])
                                ->mapWithKeys(fn (PromoCode $code): array => [$code->id => $code->code.' — '.$code->name])
                                ->all();
                        })
                        ->getOptionLabelUsing(function (mixed $value): ?string {
                            $code = PromoCode::query()->find($value);

                            return $code === null ? null : $code->code.' — '.$code->name;
                        }),
                ]),

            Section::make(__('promo_admin.links.sections.period'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label(__('promo_admin.links.fields.starts_at'))
                        ->seconds(false),
                    DateTimePicker::make('ends_at')
                        ->label(__('promo_admin.links.fields.ends_at'))
                        ->seconds(false)
                        ->rules([fn (Get $get): Closure => self::endsAfterStarts($get('starts_at'))]),
                ]),

            Section::make(__('promo_admin.links.sections.utm'))
                ->description(__('promo_admin.links.fields.utm_hint'))
                ->columns(['default' => 1, 'md' => 2])
                ->schema(array_map(
                    fn (string $key): TextInput => TextInput::make($key)
                        ->label(__('promo_admin.links.fields.'.$key))
                        ->maxLength(PromoLinkResolver::MAX_UTM)
                        ->regex('/^[A-Za-z0-9._-]*$/')
                        ->validationMessages(['regex' => __('promo_admin.links.validation.utm_format')]),
                    PromoLink::UTM_KEYS,
                )),
        ]);
    }

    /**
     * PM-21: lower-case Latin letters, digits and hyphen, 3–64 characters, unique.
     */
    private static function slugRule(?PromoLink $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $slug = trim((string) $value);

            if (preg_match(PromoLinkSaver::SLUG_PATTERN, $slug) !== 1) {
                $fail(__('promo_admin.links.validation.slug_format'));

                return;
            }

            $taken = PromoLink::query()
                ->where('slug', $slug)
                ->when($record?->exists, fn ($query) => $query->whereKeyNot($record?->getKey()))
                ->exists();

            if ($taken) {
                $fail(__('promo_admin.links.validation.slug_unique'));
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
     * @return array<int, string> variant id => «Цвет: красный, Размер: M»
     */
    private static function variantOptions(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $variants = ProductVariant::query()->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->limit(200)->get();
        $labels = app(VariantLabel::class)->forMany($variants);
        $options = [];

        foreach ($variants as $variant) {
            $label = $labels[$variant->id] ?? '';
            $options[$variant->id] = $variant->sku.($label === '' ? '' : ' — '.$label);
        }

        return $options;
    }
}
