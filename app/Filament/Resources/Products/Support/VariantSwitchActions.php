<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Support;

use App\Exceptions\VariantException;
use App\Filament\Resources\Products\ProductResource;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\Product;
use App\Models\User;
use App\Services\Users\PermissionService;
use App\Services\Variants\VariantService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The header actions of the product page that switch variants on and off and change the axes (VR-02, VR-25). The
 * rules (axes 1…3, no unfinished requests, current data into the first variant) live in {@see VariantService}; a refusal
 * is shown with its message (it lists the numbers of the blocking requests).
 */
final class VariantSwitchActions
{
    /**
     * @return list<Action>
     */
    public static function make(): array
    {
        return [self::enable(), self::axes(), self::disable()];
    }

    /**
     * «Включить модификации»: axes and, by wish, «Создать модификацию из текущих данных».
     */
    public static function enable(): Action
    {
        return Action::make('enable_variants')
            ->label(__('admin_variants.switch.enable'))
            ->icon(Heroicon::OutlinedSquares2x2)
            ->color('gray')
            ->visible(fn (Product $record): bool => ! $record->has_variants && ! $record->trashed() && self::canUpdate())
            ->modalHeading(__('admin_variants.switch.enable_heading'))
            ->modalDescription(fn (Product $record): string => self::requestsNote($record) ?? __('admin_variants.switch.enable_description'))
            ->schema([
                self::axesSelect(),
                Toggle::make('from_current')
                    ->label(__('admin_variants.switch.from_current'))
                    ->helperText(__('admin_variants.switch.from_current_hint'))
                    ->default(false)
                    ->live(),
                self::currentValue(1),
                self::currentValue(2),
                self::currentValue(3),
            ])
            ->action(function (Product $record, array $data, Component $livewire): void {
                abort_unless(self::canUpdate(), 403);

                $axes = array_map('intval', array_values((array) ($data['axes'] ?? [])));
                $values = null;

                if ((bool) ($data['from_current'] ?? false)) {
                    $values = [];

                    foreach (array_keys($axes) as $position) {
                        $values[] = (int) ($data['value_'.($position + 1)] ?? 0);
                    }
                }

                try {
                    app(VariantService::class)->enable($record, $axes, $values);
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    throw new Halt;
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();

                    throw new Halt;
                }

                Notification::make()->success()->title(__('admin_variants.switch.enabled'))->send();
                $livewire->redirect(ProductResource::getUrl('edit', ['record' => $record]));
            });
    }

    /**
     * «Изменить опции»: the axes of a card with variants (refused while variants exist and the set changes).
     */
    public static function axes(): Action
    {
        return Action::make('edit_axes')
            ->label(__('admin_variants.switch.axes'))
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->visible(fn (Product $record): bool => $record->has_variants && ! $record->trashed() && self::canUpdate())
            ->modalHeading(__('admin_variants.switch.axes_heading'))
            ->modalDescription(__('admin_variants.switch.axes_description'))
            ->fillForm(fn (Product $record): array => ['axes' => app(VariantService::class)->axes($record)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()])
            ->schema([self::axesSelect()])
            ->action(function (Product $record, array $data, Component $livewire): void {
                abort_unless(self::canUpdate(), 403);

                try {
                    app(VariantService::class)->setAxes($record, array_map('intval', array_values((array) ($data['axes'] ?? []))));
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    throw new Halt;
                }

                $livewire->redirect(ProductResource::getUrl('edit', ['record' => $record]));
            });
    }

    /**
     * «Выключить модификации»: refused while unfinished requests contain the product (VR-25).
     */
    public static function disable(): Action
    {
        return Action::make('disable_variants')
            ->label(__('admin_variants.switch.disable'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Product $record): bool => $record->has_variants && ! $record->trashed() && self::canUpdate())
            ->modalHeading(__('admin_variants.switch.disable_heading'))
            ->modalDescription(fn (Product $record): string => self::requestsNote($record) ?? __('admin_variants.switch.disable_description'))
            // Irreversible (D-115): the card article has to be typed.
            ->schema(fn (Product $record): array => [
                TextInput::make('confirm_sku')
                    ->label(__('admin_variants.switch.disable_confirm_label', ['sku' => $record->sku]))
                    ->required()
                    ->in([$record->sku])
                    ->validationMessages(['in' => __('admin_variants.switch.disable_confirm_mismatch')]),
            ])
            ->action(function (Product $record, array $data, Component $livewire): void {
                abort_unless(self::canUpdate(), 403);

                if (trim((string) ($data['confirm_sku'] ?? '')) !== $record->sku) {
                    Notification::make()->danger()->title(__('admin_variants.switch.disable_confirm_mismatch'))->send();

                    throw new Halt;
                }

                try {
                    app(VariantService::class)->disable($record);
                } catch (VariantException $e) {
                    Notification::make()->danger()->title($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title(__('admin_variants.switch.disabled'))->send();
                $livewire->redirect(ProductResource::getUrl('edit', ['record' => $record]));
            });
    }

    private static function axesSelect(): Select
    {
        return Select::make('axes')
            ->label(__('admin_variants.switch.axes_label'))
            ->helperText(__('admin_variants.switch.axes_hint'))
            ->multiple()
            ->options(fn (): array => OptionType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all())
            ->minItems(1)
            ->maxItems(VariantService::MAX_AXES)
            ->required()
            ->searchable()
            ->native(false)
            ->live();
    }

    /**
     * The value of the n-th chosen axis for the first variant made from the current data.
     */
    private static function currentValue(int $position): Select
    {
        $axisOf = fn (Get $get): ?int => isset(array_values((array) $get('axes'))[$position - 1]) ? (int) array_values((array) $get('axes'))[$position - 1] : null;
        $shown = fn (Get $get): bool => (bool) $get('from_current') && $axisOf($get) !== null;

        return Select::make('value_'.$position)
            ->label(fn (Get $get): string => ($id = $axisOf($get)) !== null ? (string) OptionType::query()->whereKey($id)->value('name') : '')
            ->options(fn (Get $get): array => ($id = $axisOf($get)) !== null
                ? OptionValue::query()->where('option_type_id', $id)->orderBy('sort_order')->orderBy('id')->limit(500)->pluck('value', 'id')->all()
                : [])
            ->searchable()
            ->native(false)
            ->visible($shown)
            ->required($shown);
    }

    /**
     * The unfinished requests that block switching (VR-25), shown before the person confirms.
     */
    private static function requestsNote(Product $product): ?string
    {
        $numbers = app(VariantService::class)->unfinishedRequestNumbers($product);

        return $numbers === [] ? null : __('admin_variants.switch.blocked', ['numbers' => implode(', ', $numbers)]);
    }

    private static function canUpdate(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PermissionService::class)->allows($user, 'products.update');
    }
}
