<?php

declare(strict_types=1);

namespace App\Filament\Resources\Exports;

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Models\Category;
use App\Models\Export;
use App\Models\PromoCodeBatch;
use App\Models\User;
use App\Services\Export\CustomerExportService;
use App\Services\Users\PermissionService;
use App\Support\FileSize;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use UnitEnum;

/**
 * Export journal and the «Экспорт» entry of the «Каталог» group (EX-01, EX-03). Administrators see every export,
 * managers their own. Files are kept for 7 days.
 */
class ExportResource extends Resource
{
    protected static ?string $model = Export::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static ?string $slug = 'exports';

    protected static ?int $navigationSort = 60;

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('shop.navigation.catalog');
    }

    public static function getModelLabel(): string
    {
        return __('export.resource.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('export.resource.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if ($user instanceof User && ! app(PermissionService::class)->isAdministrator($user)) {
            $query->where('exports.user_id', $user->id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user:id,name', 'customer:id,name']))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('export.table.created_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('export.table.type'))
                    ->formatStateUsing(fn (ExportType|string $state): string => ($state instanceof ExportType ? $state : ExportType::from($state))->label()),
                TextColumn::make('filters')
                    ->label(__('export.table.filters'))
                    ->state(fn (Export $record): string => self::describeParams($record))
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('export.table.status'))
                    ->badge()
                    ->color(fn (ExportStatus|string $state): string => match ($state instanceof ExportStatus ? $state : ExportStatus::from($state)) {
                        ExportStatus::Completed => 'success',
                        ExportStatus::Failed => 'danger',
                        ExportStatus::Processing => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (ExportStatus|string $state): string => ($state instanceof ExportStatus ? $state : ExportStatus::from($state))->label())
                    ->tooltip(fn (Export $record): ?string => $record->failure_message),
                TextColumn::make('rows')
                    ->label(__('export.table.rows'))
                    ->numeric(),
                TextColumn::make('user.name')
                    ->label(__('export.table.user'))
                    ->placeholder('—')
                    ->visible(fn (Component $livewire): bool => ! self::onCustomersTab($livewire)),
                // CX-13: the tab «Выгрузки покупателей» shows who asked, the size and the end of the download period.
                TextColumn::make('customer.name')
                    ->label(__('customer_export.admin.customer'))
                    ->placeholder('—')
                    ->visible(fn (Component $livewire): bool => self::onCustomersTab($livewire)),
                TextColumn::make('file_size')
                    ->label(__('customer_export.admin.size'))
                    ->state(fn (Export $record): string => $record->file_size === null ? '—' : FileSize::format($record->file_size))
                    ->visible(fn (Component $livewire): bool => self::onCustomersTab($livewire)),
                TextColumn::make('expires_at')
                    ->label(__('customer_export.admin.expires'))
                    ->dateTime()
                    ->placeholder('—')
                    ->visible(fn (Component $livewire): bool => self::onCustomersTab($livewire)),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('exports.created_at')->orderByDesc('exports.id'))
            ->poll('10s')
            ->emptyStateHeading(__('export.table.empty_heading'))
            ->recordActions([
                Action::make('download')
                    ->label(__('export.resource.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (Export $record): string => route('admin.exports.download', $record))
                    ->visible(fn (Export $record): bool => ! $record->isCustomerExport() && $record->status === ExportStatus::Completed && $record->file_path !== null),
                // CX-13: an administrator removes the file of a customer export; the customer's link then answers 410.
                Action::make('delete_file')
                    ->label(__('customer_export.admin.delete_file'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('customer_export.admin.delete_file_heading'))
                    ->modalDescription(__('customer_export.admin.delete_file_description'))
                    ->visible(fn (Export $record): bool => $record->isCustomerExport() && $record->file_path !== null && self::isAdministrator())
                    ->action(function (Export $record): void {
                        abort_unless(self::isAdministrator(), 403);
                        app(CustomerExportService::class)->deleteFile($record);
                        Notification::make()->success()->title(__('customer_export.admin.file_deleted'))->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExports::route('/'),
        ];
    }

    /**
     * Only administrators see the exports of customers (they hold personal data of orders) and may delete their files.
     */
    public static function isAdministrator(): bool
    {
        $user = Auth::user();

        return $user instanceof User && app(PermissionService::class)->isAdministrator($user);
    }

    private static function onCustomersTab(Component $livewire): bool
    {
        return ($livewire->activeTab ?? null) === ListExports::TAB_CUSTOMERS;
    }

    private static function describeParams(Export $export): string
    {
        $params = $export->params ?? [];
        $parts = [];

        if ($export->type->isCustomerOrders()) {
            return __('customer_export.admin.period', ['from' => $params['from'] ?? '…', 'until' => $params['until'] ?? '…']);
        }

        if ($export->type === ExportType::Orders) {
            return self::describeOrderParams($params);
        }

        if ($export->type === ExportType::PromoBatch) {
            return (string) (PromoCodeBatch::query()->whereKey((int) ($params['batch_id'] ?? 0))->value('name') ?? '#'.($params['batch_id'] ?? ''));
        }

        if (isset($params['product_ids']) && is_array($params['product_ids'])) {
            $parts[] = __('export.table.filter_selected', ['count' => count($params['product_ids'])]);
        } elseif (filled($params['category_id'] ?? null)) {
            $name = Category::query()->whereKey((int) $params['category_id'])->value('name');
            $parts[] = __('export.table.filter_category', ['name' => $name ?? '#'.$params['category_id']]);
        } else {
            $parts[] = __('export.table.filter_all');
        }

        $active = $params['active'] ?? 'all';

        if ($active === 'active' || $active === 'inactive') {
            $parts[] = __('export.form.active_'.$active);
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private static function describeOrderParams(array $params): string
    {
        $parts = [];

        foreach (['tab', 'type'] as $key) {
            $type = filled($params[$key] ?? null) ? OrderType::tryFrom((string) $params[$key]) : null;

            if ($type !== null) {
                $parts[$type->value] = $type->pluralLabel();
            }
        }

        $status = filled($params['status'] ?? null) ? OrderStatus::tryFrom((string) $params['status']) : null;

        if ($status !== null) {
            $parts[] = $status->label();
        }

        if (filled($params['created_from'] ?? null) || filled($params['created_until'] ?? null)) {
            $parts[] = __('admin_orders.export.period', [
                'from' => $params['created_from'] ?? '…',
                'until' => $params['created_until'] ?? '…',
            ]);
        }

        if (filled($params['delivery_method'] ?? null)) {
            $parts[] = (string) $params['delivery_method'];
        }

        if (filled($params['search'] ?? null)) {
            $parts[] = __('admin_orders.export.search', ['term' => (string) $params['search']]);
        }

        return $parts === [] ? __('admin_orders.export.all') : implode(', ', $parts);
    }
}
