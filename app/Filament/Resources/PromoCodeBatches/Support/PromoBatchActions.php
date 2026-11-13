<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoCodeBatches\Support;

use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Resources\PromoCodes\Schemas\PromoConditionFields;
use App\Models\PromoCodeBatch;
use App\Models\User;
use App\Services\Promo\PromoBatchService;
use App\Services\Promo\PromoCodeSaver;
use App\Services\Users\PermissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * The batch actions of PM-15 / PM-16, used by the code list and by the batch resource. All of them need
 * `promo_codes.generate`: the action is hidden without it and refuses to run (the check is repeated in the callback).
 */
final class PromoBatchActions
{
    public static function allowed(): bool
    {
        return app(PermissionService::class)->allowsCurrent('promo_codes.generate');
    }

    /**
     * «Сгенерировать пакет»: name, prefix, count, length and the template of the shared conditions.
     */
    public static function generate(): Action
    {
        return Action::make('generate')
            ->label(__('promo_admin.batches.actions.generate'))
            ->icon('heroicon-o-sparkles')
            ->color('gray')
            ->visible(fn (): bool => self::allowed())
            ->modalHeading(__('promo_admin.batches.actions.generate'))
            ->modalDescription(__('promo_admin.batches.actions.generate_hint'))
            ->modalWidth('4xl')
            ->schema([
                Section::make(__('promo_admin.batches.sections.generation'))->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('name')
                            ->label(__('promo_admin.batches.fields.name'))
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('prefix')
                            ->label(__('promo_admin.batches.fields.prefix'))
                            ->helperText(__('promo_admin.batches.fields.prefix_hint'))
                            ->maxLength(PromoBatchService::MAX_PREFIX)
                            ->regex('/^[A-Za-z0-9-]*$/')
                            ->validationMessages(['regex' => __('promo.errors.prefix_invalid')]),
                        TextInput::make('count')
                            ->label(__('promo_admin.batches.fields.count'))
                            ->helperText(__('promo_admin.batches.fields.count_hint', ['max' => PromoBatchService::MAX_COUNT]))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(PromoBatchService::MAX_COUNT)
                            ->default(100)
                            ->required(),
                        TextInput::make('code_length')
                            ->label(__('promo_admin.batches.fields.code_length'))
                            ->helperText(__('promo_admin.batches.fields.code_length_hint', ['min' => PromoBatchService::MIN_LENGTH, 'max' => PromoBatchService::MAX_LENGTH]))
                            ->numeric()
                            ->integer()
                            ->minValue(PromoBatchService::MIN_LENGTH)
                            ->maxValue(PromoBatchService::MAX_LENGTH)
                            ->default(8)
                            ->required(),
                    ]),
                ]),
                ...self::templateSchema(),
            ])
            ->action(function (array $data): void {
                abort_unless(self::allowed(), 403);

                $user = Auth::user();

                try {
                    $batch = app(PromoBatchService::class)->create(
                        (string) $data['name'],
                        (string) ($data['prefix'] ?? ''),
                        (int) $data['count'],
                        (int) $data['code_length'],
                        app(PromoCodeSaver::class)->templateFromState($data),
                        $user instanceof User ? $user : null,
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()->danger()->title(__('promo_admin.batches.actions.failed'))->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('promo_admin.batches.actions.generated', ['name' => $batch->name, 'count' => $batch->count]))
                    ->send();
            });
    }

    /**
     * «Применить шаблон»: edits the template of the batch and carries it over to the unused codes.
     */
    public static function applyTemplate(): Action
    {
        return Action::make('applyTemplate')
            ->label(__('promo_admin.batches.actions.apply_template'))
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->visible(fn (): bool => self::allowed())
            ->modalHeading(__('promo_admin.batches.actions.apply_template'))
            ->modalDescription(__('promo_admin.batches.actions.apply_template_hint'))
            ->modalWidth('4xl')
            ->fillForm(fn (PromoCodeBatch $record): array => app(PromoCodeSaver::class)->templateToState($record->template))
            ->schema(self::templateSchema())
            ->action(function (PromoCodeBatch $record, array $data): void {
                abort_unless(self::allowed(), 403);

                $count = app(PromoBatchService::class)->applyTemplate($record, app(PromoCodeSaver::class)->templateFromState($data));

                Notification::make()->success()->title(__('promo_admin.batches.actions.template_applied', ['count' => $count]))->send();
            });
    }

    /**
     * «Экспорт»: asks for the CSV/Excel of the codes; the file appears in the export journal (PM-16).
     */
    public static function export(): Action
    {
        return Action::make('export')
            ->label(__('promo_admin.batches.actions.export'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (PromoCodeBatch $record): bool => self::allowed() && $record->generated > 0)
            ->action(function (PromoCodeBatch $record): void {
                abort_unless(self::allowed(), 403);

                $user = Auth::user();
                app(PromoBatchService::class)->requestExport($record, $user instanceof User ? $user : null);

                Notification::make()->success()
                    ->title(__('promo_admin.batches.actions.export_requested'))
                    ->actions([
                        Action::make('open')
                            ->label(__('promo_admin.batches.actions.export_open'))
                            ->url(ExportResource::getUrl('index')),
                    ])
                    ->send();
            });
    }

    /**
     * The template fields shared by the generation and the template change.
     *
     * @return list<Component>
     */
    private static function templateSchema(): array
    {
        return [
            Toggle::make('is_active')
                ->label(__('promo_admin.codes.fields.is_active'))
                ->default(true),
            ...PromoConditionFields::sections(withLimits: false),
        ];
    }
}
