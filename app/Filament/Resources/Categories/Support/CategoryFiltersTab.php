<?php

declare(strict_types=1);

namespace App\Filament\Resources\Categories\Support;

use App\Enums\FilterSource;
use App\Models\Attribute;
use App\Models\Category;
use App\Services\Catalog\CategoryFilterEditor;
use App\Services\Catalog\CategoryTree;
use App\Services\Variants\CategoryFilterRows;
use App\Settings\CatalogSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * The «Фильтры» tab of the category form (FL-05, FL-06, FL-17, FL-19): the filter set with drag sorting, the
 * inheritance note with «Задать свой набор» / «Сбросить к наследуемому», the preview «значений / товаров» of every row,
 * the warning for a row without values and the «Открыть на сайте» link with an example filter. The form state is
 * `filters_own` + `filters`; {@see CategoryFilterEditor} reads and saves it.
 */
final class CategoryFiltersTab
{
    public static function make(): Tab
    {
        return Tab::make(__('admin_attributes.category.tab'))
            ->visibleOn('edit')
            ->schema([
                Hidden::make('filters_own')->default(false),
                Text::make(fn (?Model $record): string => self::inheritNote($record))
                    ->color('gray')
                    ->visible(fn (Get $get): bool => ! (bool) $get('filters_own')),
                Actions::make([
                    self::ownAction(),
                    self::resetAction(),
                    self::openOnSiteAction(),
                ])->key('filters_actions'),
                self::repeater()
                    ->visible(fn (Get $get): bool => (bool) $get('filters_own')),
            ]);
    }

    private static function repeater(): Repeater
    {
        return Repeater::make('filters')
            ->label(__('admin_attributes.category.filters'))
            ->helperText(fn (): string => __('admin_attributes.category.filters_hint', ['max' => app(CatalogSettings::class)->filters_max_per_category]))
            ->schema([
                // FL-05, VR-19: a characteristic or an option with the flag «использовать как фильтр».
                Select::make('source')
                    ->label(__('admin_attributes.category.source'))
                    ->options(collect(FilterSource::cases())->mapWithKeys(fn (FilterSource $source): array => [$source->value => $source->label()])->all())
                    ->default(FilterSource::Attribute->value)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live(),
                Select::make('attribute_id')
                    ->label(__('admin_attributes.category.attribute'))
                    ->options(fn (): array => Attribute::query()->where('is_active', true)->ordered()->get()->mapWithKeys(fn (Attribute $a): array => [$a->id => $a->signature().($a->is_filterable ? '' : ' ('.__('admin_attributes.category.not_filterable').')')])->all())
                    ->searchable()
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('source') !== FilterSource::Option->value)
                    ->visible(fn (Get $get): bool => $get('source') !== FilterSource::Option->value)
                    ->live()
                    ->distinct(),
                Select::make('option_type_id')
                    ->label(__('admin_variants.category.option'))
                    ->options(fn (): array => app(CategoryFilterRows::class)->optionChoices())
                    ->getOptionLabelUsing(fn (mixed $value): ?string => app(CategoryFilterRows::class)->optionName($value))
                    ->searchable()
                    ->native(false)
                    ->required(fn (Get $get): bool => $get('source') === FilterSource::Option->value)
                    ->visible(fn (Get $get): bool => $get('source') === FilterSource::Option->value)
                    ->live()
                    ->distinct(),
                Toggle::make('is_expanded')
                    ->label(__('admin_attributes.category.expanded'))
                    ->default(false),
                Text::make(fn (Get $get, ?Model $record): string => $get('source') === FilterSource::Option->value ? '' : self::previewLine($get('attribute_id'), $record))
                    ->color(fn (Get $get, ?Model $record): string => $get('source') !== FilterSource::Option->value && self::previewIsEmpty($get('attribute_id'), $record) ? 'warning' : 'gray'),
            ])
            ->columns(2)
            ->reorderable()
            ->defaultItems(0)
            ->maxItems(fn (): int => app(CatalogSettings::class)->filters_max_per_category)
            ->addActionLabel(__('admin_attributes.category.add'))
            ->itemLabel(fn (array $state): ?string => ($state['source'] ?? null) === FilterSource::Option->value
                ? app(CategoryFilterRows::class)->optionName($state['option_type_id'] ?? null)
                : (filled($state['attribute_id'] ?? null) ? Attribute::query()->find((int) $state['attribute_id'])?->signature() : null));
    }

    /**
     * «Задать свой набор»: starts an own set as a copy of the inherited one.
     */
    private static function ownAction(): Action
    {
        return Action::make('own_filter_set')
            ->label(__('admin_attributes.category.own'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->visible(fn (Get $get): bool => ! (bool) $get('filters_own'))
            ->action(function (Set $set, ?Model $record): void {
                $rows = $record instanceof Category ? app(CategoryFilterRows::class)->inheritedRows($record) : [];

                $set('filters_own', true);
                $set('filters', $rows);
            });
    }

    /**
     * «Сбросить к наследуемому»: the own set is removed when the category is saved.
     */
    private static function resetAction(): Action
    {
        return Action::make('reset_filter_set')
            ->label(__('admin_attributes.category.reset'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('admin_attributes.category.reset_description'))
            ->visible(fn (Get $get): bool => (bool) $get('filters_own'))
            ->action(function (Set $set): void {
                $set('filters_own', false);
                $set('filters', []);
            });
    }

    /**
     * FL-19: the category page with an example of the chosen filters.
     */
    private static function openOnSiteAction(): Action
    {
        return Action::make('open_filters_on_site')
            ->label(__('admin_attributes.category.open_on_site'))
            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
            ->color('gray')
            ->visible(fn (?Model $record): bool => $record instanceof Category && Route::has('catalog.category'))
            ->url(fn (Get $get, ?Model $record): ?string => self::siteUrl($get, $record))
            ->openUrlInNewTab();
    }

    public static function siteUrl(Get $get, ?Model $record): ?string
    {
        if (! $record instanceof Category || ! Route::has('catalog.category')) {
            return null;
        }

        $ids = [];

        foreach ((array) $get('filters') as $row) {
            if (is_array($row) && filled($row['attribute_id'] ?? null)) {
                $ids[] = (int) $row['attribute_id'];
            }
        }

        $query = (bool) $get('filters_own') ? app(CategoryFilterEditor::class)->exampleQuery($record, $ids) : [];
        $url = route('catalog.category', $record->slug);

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    private static function inheritNote(?Model $record): string
    {
        if (! $record instanceof Category) {
            return '';
        }

        $editor = app(CategoryFilterEditor::class);
        $from = $editor->inheritedFrom($record);
        $name = $from !== null ? app(CategoryTree::class)->node($from)?->name : null;

        return $name !== null
            ? __('admin_attributes.category.inherited_from', ['name' => $name])
            : __('admin_attributes.category.no_set');
    }

    private static function previewLine(mixed $attributeId, ?Model $record): string
    {
        if (! filled($attributeId) || ! $record instanceof Category) {
            return '';
        }

        $preview = app(CategoryFilterEditor::class)->preview($record, (int) $attributeId);

        return $preview['products'] === 0
            ? __('admin_attributes.category.preview_empty')
            : __('admin_attributes.category.preview', $preview);
    }

    private static function previewIsEmpty(mixed $attributeId, ?Model $record): bool
    {
        return filled($attributeId) && $record instanceof Category && app(CategoryFilterEditor::class)->preview($record, (int) $attributeId)['products'] === 0;
    }
}
