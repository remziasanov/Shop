<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\ExportType;
use App\Enums\OrderType;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Export\OrdersExporter;
use App\Services\Orders\NewRequestsCounter;
use App\Services\Orders\OrderQuery;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * @phpstan-import-type Params from OrderQuery
 */
class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * Tabs «Все / Заказы / Резервы / Заявки» with the count of new requests in each (OR-01).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = app(NewRequestsCounter::class)->byType();

        $tabs = [
            'all' => Tab::make(__('admin_orders.tabs.all'))
                ->badge(array_sum($counts) ?: null)
                ->badgeColor('warning')
                ->badgeTooltip(__('admin_orders.tabs.new_badge')),
        ];

        foreach (OrderType::cases() as $type) {
            $tabs[$type->value] = Tab::make($type->pluralLabel())
                ->badge($counts[$type->value] ?: null)
                ->badgeColor('warning')
                ->badgeTooltip(__('admin_orders.tabs.new_badge'))
                ->modifyQueryUsing(fn (Builder $query): Builder => OrderQuery::apply($query, ['tab' => $type->value]));
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label(__('admin_orders.actions.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->modalHeading(__('admin_orders.actions.export_heading'))
                ->modalDescription(__('admin_orders.actions.export_description'))
                ->visible(fn (): bool => Gate::allows('export', Order::class))
                ->action(function (): void {
                    abort_unless(Gate::allows('export', Order::class), 403);

                    $params = $this->exportParams();

                    if (app(OrdersExporter::class)->count($params) === 0) {
                        Notification::make()->warning()
                            ->title(__('admin_orders.actions.export_empty'))
                            ->body(__('admin_orders.actions.export_empty_body'))
                            ->send();

                        return;
                    }

                    $user = Auth::user();

                    ListExports::start($params, $user instanceof User ? $user : null, ExportType::Orders);
                }),
        ];
    }

    /**
     * The current tab, table filters and search as a serializable array (EX-02).
     *
     * @return Params
     */
    public function exportParams(): array
    {
        $period = $this->getTableFilterState('period') ?? [];

        return array_filter([
            'tab' => $this->activeTab !== 'all' ? $this->activeTab : null,
            'type' => $this->getTableFilterState('type')['value'] ?? null,
            'status' => $this->getTableFilterState('status')['value'] ?? null,
            'created_from' => $period['created_from'] ?? null,
            'created_until' => $period['created_until'] ?? null,
            'delivery_method' => $this->getTableFilterState('delivery_method')['value'] ?? null,
            'search' => filled($this->tableSearch) ? trim((string) $this->tableSearch) : null,
        ], fn (mixed $value): bool => filled($value));
    }
}
