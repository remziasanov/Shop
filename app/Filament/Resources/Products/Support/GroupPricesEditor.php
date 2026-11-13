<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Models\CustomerGroup;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\GroupPriceEditor;
use App\Services\Pricing\TierIssue;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Form components of «Цены для групп» (GP-17), shared by the product form tab and the variant action «Цены групп»:
 * one collapsible block per customer group with the repeater «от (шт) — цена» (`group_prices.g<id>`), the price of the
 * group without an exact price, validation errors and warnings as they would be saved (GP-14) and the preview of the
 * price for a quantity (`group_qty.g<id>`, not saved).
 */
final class GroupPricesEditor
{
    public const STATE = 'group_prices';

    public const QTY_STATE = 'group_qty';

    /**
     * @param  Closure(): (Product|ProductVariant|null)  $item  the product or variant the prices belong to
     * @return list<Component>
     */
    public static function components(Closure $item): array
    {
        $editor = app(GroupPriceEditor::class);
        $components = [
            Text::make(__('admin_group_prices.editor.intro'))->color('gray'),
        ];

        foreach ($editor->groups() as $group) {
            $components[] = self::block($group, $item);
        }

        return $components;
    }

    /**
     * Initial state of the form fields for the item: saved rows and the preview quantity of every group.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fillState(Product|ProductVariant $item): array
    {
        $editor = app(GroupPriceEditor::class);
        $quantities = [];

        foreach ($editor->groups() as $group) {
            $quantities[GroupPriceEditor::key($group)] = 1;
        }

        return [self::STATE => $editor->state($item), self::QTY_STATE => $quantities];
    }

    /**
     * Validation errors of the submitted state keyed by the form field (`data.group_prices.g5`) for a page that shows
     * them as ordinary form errors; null when everything can be saved.
     *
     * @param  array<string, mixed>  $state  the `group_prices` part of the form state
     */
    public static function errors(Product|ProductVariant $item, array $state, string $prefix = 'data.'): ?ValidationException
    {
        $messages = [];

        foreach (app(GroupPriceEditor::class)->validate($item, $state) as $key => $validation) {
            if (! $validation->passes()) {
                $messages[$prefix.self::STATE.'.'.$key] = $validation->errorMessages();
            }
        }

        return $messages === [] ? null : ValidationException::withMessages($messages);
    }

    private static function block(CustomerGroup $group, Closure $item): Section
    {
        $key = GroupPriceEditor::key($group);
        $path = self::STATE.'.'.$key;
        $qtyPath = self::QTY_STATE.'.'.$key;
        $editor = app(GroupPriceEditor::class);

        $rows = static fn (Get $get): array => array_values(array_filter((array) ($get($path) ?? []), 'is_array'));

        return Section::make($group->name)
            ->description(self::description($group))
            ->collapsible()
            ->collapsed(fn (Get $get): bool => $rows($get) === [])
            ->persistCollapsed(false)
            ->compact()
            ->schema([
                Text::make(function () use ($editor, $group, $item): string {
                    $target = $item();

                    return $target === null ? '' : __('admin_group_prices.editor.without_exact', [
                        'price' => Money::format($editor->withoutExactPrice($target, $group)),
                    ]);
                })->color('gray'),
                Repeater::make($path)
                    ->label(__('admin_group_prices.editor.rows'))
                    ->helperText(__('admin_group_prices.editor.rows_hint'))
                    ->schema([
                        TextInput::make('min_qty')
                            ->label(__('admin_group_prices.editor.min_qty'))
                            ->inputMode('numeric')
                            ->maxLength(9)
                            ->live(onBlur: true),
                        TextInput::make('price')
                            ->label(__('admin_group_prices.editor.price'))
                            ->inputMode('decimal')
                            ->suffix(Money::CURRENCY_SIGN)
                            ->maxLength(20)
                            ->live(onBlur: true),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel(__('admin_group_prices.editor.add'))
                    ->live(),
                Text::make(fn (Get $get): HtmlString => self::issues($editor, $item, $group, $rows($get), true))->color('danger'),
                Text::make(fn (Get $get): HtmlString => self::issues($editor, $item, $group, $rows($get), false))->color('warning'),
                TextInput::make($qtyPath)
                    ->label(__('admin_group_prices.editor.preview_qty'))
                    ->inputMode('numeric')
                    ->maxLength(9)
                    ->default(1)
                    ->dehydrated(false)
                    ->live(onBlur: true),
                Text::make(function (Get $get) use ($editor, $item, $rows, $group, $qtyPath): string {
                    $target = $item();

                    return $target === null ? '' : $editor->previewLine($target, $group, $rows($get), $get($qtyPath));
                })->weight('bold'),
            ]);
    }

    /**
     * Errors or warnings of the rows as they would be saved, one per line.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function issues(GroupPriceEditor $editor, Closure $item, CustomerGroup $group, array $rows, bool $errors): HtmlString
    {
        $target = $item();

        if ($target === null) {
            return new HtmlString('');
        }

        $key = GroupPriceEditor::key($group);
        $validation = $editor->validate($target, [$key => $rows])[$key] ?? null;

        if ($validation === null) {
            return new HtmlString('');
        }

        $lines = array_map(
            fn (TierIssue $issue): string => e($errors ? $issue->message : __('admin_group_prices.editor.warning', ['message' => $issue->message])),
            $errors ? $validation->errors : $validation->warnings,
        );

        return new HtmlString(implode('<br>', $lines));
    }

    private static function description(CustomerGroup $group): string
    {
        $parts = [$group->code];

        if (bccomp((string) $group->default_discount_percent, '0', 2) > 0) {
            $parts[] = __('admin_group_prices.editor.group_discount', ['percent' => rtrim(rtrim((string) $group->default_discount_percent, '0'), '.')]);
        }

        return implode(' · ', $parts);
    }
}
