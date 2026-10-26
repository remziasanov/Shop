<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\Products\Support\PhotoUpload;
use App\Models\Product;
use App\Services\Catalog\CategoryTree;
use App\Services\Content\HtmlSanitizer;
use App\Services\Home\HomeHtmlBlocks;
use App\Services\Home\HomeIcons;
use App\Services\Home\HomeImages;
use App\Services\Home\HomeLink;
use Closure;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Storage;

/**
 * The Filament Builder of the home page (5.2, AD-20): block types, their fields and labels.
 * The «HTML» block exists only for administrators (6.13).
 */
final class HomeBlocksSchema
{
    public static function builder(bool $isAdmin): Builder
    {
        $blocks = [
            self::banner(),
            self::slider(),
            self::categories(),
            self::products(),
            self::text(),
            self::advantages(),
        ];

        if ($isAdmin) {
            $blocks[] = self::html();
        }

        return Builder::make('blocks')
            ->hiddenLabel()
            ->blocks($blocks)
            ->collapsible()
            ->reorderable()
            ->reorderableWithDragAndDrop()
            ->cloneable()
            ->addActionLabel(__('home.admin.add_block'))
            ->blockPickerColumns(['default' => 2, 'md' => 3])
            ->columnSpanFull();
    }

    private static function banner(): Block
    {
        return self::block('banner', 'photo', [
            self::visible(),
            TextInput::make('title')->label(__('home.fields.title'))->maxLength(150),
            TextInput::make('subtitle')->label(__('home.fields.subtitle'))->maxLength(300),
            TextInput::make('image_alt')->label(__('home.fields.image_alt'))->helperText(__('home.admin.image_alt_hint'))->maxLength(200),
            ...self::imageFields('image', true),
            ...self::imageFields('image_mobile', false),
            ...self::buttonFields(),
        ]);
    }

    private static function slider(): Block
    {
        return self::block('slider', 'rectangle-stack', [
            self::visible(),
            Toggle::make('autoplay')->label(__('home.fields.autoplay'))->default(true),
            TextInput::make('interval')
                ->label(__('home.fields.interval'))
                ->numeric()->integer()->minValue(2)->maxValue(60)->default(5)->required(),
            Repeater::make('slides')
                ->label(__('home.fields.slides'))
                ->schema([
                    TextInput::make('title')->label(__('home.fields.title'))->maxLength(150),
                    TextInput::make('subtitle')->label(__('home.fields.subtitle'))->maxLength(300),
                    TextInput::make('image_alt')->label(__('home.fields.image_alt'))->helperText(__('home.admin.image_alt_hint'))->maxLength(200),
                    ...self::imageFields('image', true),
                    ...self::imageFields('image_mobile', false),
                    ...self::buttonFields(),
                ])
                ->itemLabel(fn (array $state): ?string => filled($state['title'] ?? null) ? (string) $state['title'] : null)
                ->minItems(1)
                ->maxItems(10)
                ->defaultItems(1)
                ->collapsible()
                ->reorderable()
                ->addActionLabel(__('home.admin.add_slide'))
                ->columnSpanFull(),
        ]);
    }

    private static function categories(): Block
    {
        return self::block('categories', 'squares-2x2', [
            self::visible(),
            TextInput::make('title')->label(__('home.fields.title'))->maxLength(150),
            Select::make('mode')
                ->label(__('home.fields.categories_mode'))
                ->options([
                    'auto' => __('home.categories_mode.auto'),
                    'selected' => __('home.categories_mode.selected'),
                ])
                ->default('auto')->required()->native(false)->live(),
            Select::make('categories')
                ->label(__('home.fields.categories'))
                ->multiple()->searchable()
                ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                ->visible(fn (Get $get): bool => $get('mode') === 'selected')
                ->required(fn (Get $get): bool => $get('mode') === 'selected'),
            TextInput::make('max_count')
                ->label(__('home.fields.max_count'))
                ->numeric()->integer()->minValue(1)->maxValue(24)->default(8)->required(),
        ]);
    }

    private static function products(): Block
    {
        return self::block('products', 'shopping-bag', [
            self::visible(),
            TextInput::make('title')->label(__('home.fields.title'))->maxLength(150),
            Select::make('source')
                ->label(__('home.fields.source'))
                ->options(collect(['hits', 'new', 'discounted', 'category', 'manual'])
                    ->mapWithKeys(fn (string $source): array => [$source => __('home.sources.'.$source)])->all())
                ->default('hits')->required()->native(false)->live(),
            Select::make('category_id')
                ->label(__('home.fields.category'))
                ->options(fn (): array => app(CategoryTree::class)->indentedOptions())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('source') === 'category')
                ->required(fn (Get $get): bool => $get('source') === 'category'),
            Select::make('product_ids')
                ->label(__('home.fields.product_ids'))
                ->multiple()->searchable()
                ->getSearchResultsUsing(fn (string $search): array => Product::query()
                    ->search($search)->limit(30)->get(['id', 'name', 'sku'])
                    ->mapWithKeys(fn (Product $product): array => [$product->id => $product->name.' ('.$product->sku.')'])->all())
                ->getOptionLabelsUsing(fn (array $values): array => Product::query()
                    ->whereIn('id', $values)->get(['id', 'name', 'sku'])
                    ->mapWithKeys(fn (Product $product): array => [$product->id => $product->name.' ('.$product->sku.')'])->all())
                ->visible(fn (Get $get): bool => $get('source') === 'manual')
                ->required(fn (Get $get): bool => $get('source') === 'manual'),
            TextInput::make('limit')
                ->label(__('home.fields.limit'))
                ->numeric()->integer()->minValue(1)->maxValue(24)->default(8)->required(),
            Select::make('sort')
                ->label(__('home.fields.sort'))
                ->options(collect(['default', 'price_asc', 'price_desc', 'new'])
                    ->mapWithKeys(fn (string $sort): array => [$sort => __('home.sorts.'.$sort)])->all())
                ->default('default')->required()->native(false),
            Toggle::make('show_all')->label(__('home.fields.show_all'))->default(true),
        ]);
    }

    private static function text(): Block
    {
        return self::block('text', 'document-text', [
            self::visible(),
            TextInput::make('title')->label(__('home.fields.title'))->maxLength(150),
            RichEditor::make('content')
                ->label(__('home.fields.content'))
                ->required()
                ->dehydrateStateUsing(fn (mixed $state): string => is_string($state) ? app(HtmlSanitizer::class)->sanitize($state) : '')
                ->columnSpanFull(),
        ]);
    }

    private static function advantages(): Block
    {
        return self::block('advantages', 'sparkles', [
            self::visible(),
            Repeater::make('items')
                ->label(__('home.fields.items'))
                ->schema([
                    Select::make('icon')
                        ->label(__('home.fields.icon'))
                        ->options(HomeIcons::options())
                        ->default('star')->required()->native(false),
                    TextInput::make('title')->label(__('home.fields.title'))->required()->maxLength(100),
                    Textarea::make('text')->label(__('home.fields.text'))->rows(2)->maxLength(300),
                ])
                ->itemLabel(fn (array $state): ?string => filled($state['title'] ?? null) ? (string) $state['title'] : null)
                ->minItems(1)
                ->maxItems(12)
                ->defaultItems(1)
                ->collapsible()
                ->reorderable()
                ->addActionLabel(__('home.admin.add_item'))
                ->columnSpanFull(),
        ]);
    }

    private static function html(): Block
    {
        return self::block(HomeHtmlBlocks::TYPE, 'code-bracket', [
            self::visible(),
            Textarea::make('html')
                ->label(__('home.fields.html'))
                ->helperText(__('home.admin.html_hint'))
                ->required()
                ->rows(12)
                ->extraInputAttributes(['class' => 'font-mono text-sm', 'spellcheck' => 'false'])
                ->columnSpanFull(),
        ]);
    }

    /**
     * @param  list<mixed>  $schema
     */
    private static function block(string $type, string $icon, array $schema): Block
    {
        return Block::make($type)
            ->label(fn (?array $state): string => self::blockLabel($type, $state))
            ->icon('heroicon-o-'.$icon)
            ->schema($schema)
            ->columns(2);
    }

    /**
     * «Баннер — Заголовок» and «(скрыт)» for hidden blocks.
     *
     * @param  array<string, mixed>|null  $state
     */
    private static function blockLabel(string $type, ?array $state): string
    {
        $data = is_array($state['data'] ?? null) ? $state['data'] : (is_array($state) ? $state : []);
        $label = __('home.blocks.'.$type);

        if (is_string($data['title'] ?? null) && trim($data['title']) !== '') {
            $label .= ' — '.trim($data['title']);
        }

        if (array_key_exists('visible', $data) && ! $data['visible']) {
            $label .= ' ('.__('home.admin.hidden').')';
        }

        return $label;
    }

    private static function visible(): Toggle
    {
        return Toggle::make('visible')->label(__('home.fields.visible'))->default(true)->columnSpanFull();
    }

    /**
     * @return list<mixed>
     */
    private static function buttonFields(): array
    {
        return [
            TextInput::make('button_text')->label(__('home.fields.button_text'))->maxLength(60),
            TextInput::make('button_link')
                ->label(__('home.fields.button_link'))
                ->helperText(__('home.admin.link_hint'))
                ->maxLength(500)
                ->rule(fn (): Closure => self::linkRule()),
        ];
    }

    /**
     * Preview + upload (+ remove for optional images) of one image field. State: `{field}` (stored
     * reference, never edited directly), `{field}_upload` (temporary file), `{field}_remove`.
     *
     * @return list<mixed>
     */
    private static function imageFields(string $field, bool $required): array
    {
        $label = __('home.fields.'.$field);

        $fields = [
            Hidden::make($field),
            Image::make(
                fn (Get $get): string => filled($get($field.'.small')) ? Storage::disk('public')->url((string) $get($field.'.small')) : '',
                $label,
            )
                ->imageHeight('6rem')
                ->visible(fn (Get $get): bool => filled($get($field.'.small')))
                ->columnSpanFull(),
        ];

        if (! $required) {
            $fields[] = Toggle::make($field.'_remove')
                ->label(__('home.fields.image_remove'))
                ->default(false)
                ->visible(fn (Get $get): bool => filled($get($field.'.small')));
        }

        $fields[] = FileUpload::make($field.'_upload')
            ->label($label)
            ->helperText(__('home.admin.image_hint', ['size' => PhotoUpload::effectiveMaxLabel()]))
            ->image()
            ->disk(HomeImages::TEMP_DISK)
            ->directory(HomeImages::TEMP_DIRECTORY)
            ->visibility('private')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
            ->maxSize(intdiv(PhotoUpload::effectiveMaxBytes(), 1024))
            ->required($required ? fn (Get $get): bool => blank($get($field.'.small')) : false)
            ->columnSpanFull();

        return $fields;
    }

    /**
     * Relative path or http(s) / mailto / tel link only (no javascript: and the like).
     */
    private static function linkRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && $value !== '' && ! HomeLink::isSafe($value)) {
                $fail(__('home.admin.link_invalid'));
            }
        };
    }
}
