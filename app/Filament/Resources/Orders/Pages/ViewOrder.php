<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\SalesException;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\NewRequestsCounter;
use App\Services\Orders\OrderService;
use App\Services\Orders\StatusTransitions;
use App\Settings\SalesSettings;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * @property Order $record
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        /** @var Order $order */
        $order = parent::resolveRecord($key);

        return $order->load(['items.product', 'statusHistory.user', 'statusHistory.order']);
    }

    public function getTitle(): string
    {
        return $this->record->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->changeStatusAction(),
            $this->extendAction(),
            $this->managerCommentAction(),
            Action::make('print')
                ->label(__('admin_orders.actions.print'))
                ->icon(Heroicon::OutlinedPrinter)
                ->color('gray')
                ->url(fn (): string => route('admin.orders.print', $this->record))
                ->openUrlInNewTab(),
            DeleteAction::make()
                ->label(__('admin_orders.actions.delete'))
                ->using(function (Order $record): bool {
                    app(OrderService::class)->delete($record, $this->actor());

                    return true;
                })
                ->successRedirectUrl(fn (): string => OrderResource::getUrl('index')),
        ];
    }

    private function changeStatusAction(): Action
    {
        return Action::make('change_status')
            ->label(__('admin_orders.actions.change_status'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (): bool => Gate::allows('update', $this->record) && $this->allowedStatuses() !== [])
            ->schema([
                Select::make('status')
                    ->label(__('admin_orders.actions.status'))
                    ->options(fn (): array => collect($this->allowedStatuses())
                        ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->label($this->record->type)])
                        ->all())
                    ->required()
                    ->live()
                    ->native(false),
                DateTimePicker::make('reserved_until')
                    ->label(__('admin_orders.actions.reserved_until'))
                    ->helperText(__('admin_orders.actions.reserved_until_help'))
                    ->seconds(false)
                    ->minDate(now())
                    ->visible(fn (Get $get): bool => $this->record->type === OrderType::Reservation
                        && $this->record->status === OrderStatus::New
                        && $get('status') === OrderStatus::Reserved->value),
                Textarea::make('comment')
                    ->label(__('admin_orders.actions.comment'))
                    ->helperText(__('admin_orders.actions.comment_help'))
                    ->maxLength(1000)
                    ->rows(3),
            ])
            ->action(function (array $data): void {
                abort_unless(Gate::allows('update', $this->record), 403);

                $to = OrderStatus::from((string) $data['status']);
                $until = filled($data['reserved_until'] ?? null) ? Carbon::parse($data['reserved_until']) : null;

                try {
                    app(OrderService::class)->changeStatus($this->record, $to, $this->actor(), $data['comment'] ?? null, $until);
                } catch (SalesException $e) {
                    Notification::make()->danger()->title(__('admin_orders.actions.status_failed'))->body($e->getMessage())->send();

                    return;
                }

                $this->afterChange();

                Notification::make()->success()
                    ->title(__('admin_orders.actions.status_changed', ['status' => $to->label($this->record->type)]))
                    ->send();
            });
    }

    private function extendAction(): Action
    {
        return Action::make('extend')
            ->label(__('admin_orders.actions.extend'))
            ->icon(Heroicon::OutlinedClock)
            ->visible(fn (): bool => Gate::allows('update', $this->record)
                && $this->record->type === OrderType::Reservation
                && in_array($this->record->status, [OrderStatus::New, OrderStatus::Reserved], true))
            ->schema([
                TextInput::make('hours')
                    ->label(__('admin_orders.actions.extend_hours'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->maxValue(24 * 90)
                    ->required()
                    ->default(fn (): int => app(SalesSettings::class)->reservation_hours),
            ])
            ->action(function (array $data): void {
                abort_unless(Gate::allows('update', $this->record), 403);

                try {
                    app(OrderService::class)->extendReservation($this->record, (int) $data['hours'], $this->actor());
                } catch (SalesException $e) {
                    Notification::make()->danger()->title(__('admin_orders.actions.extend_failed'))->body($e->getMessage())->send();

                    return;
                }

                $this->afterChange();

                Notification::make()->success()
                    ->title(__('admin_orders.actions.extended', ['date' => $this->record->reserved_until?->format('d.m.Y H:i')]))
                    ->send();
            });
    }

    private function managerCommentAction(): Action
    {
        return Action::make('manager_comment')
            ->label(__('admin_orders.actions.manager_comment'))
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('update', $this->record))
            ->fillForm(fn (): array => ['manager_comment' => $this->record->manager_comment])
            ->schema([
                Textarea::make('manager_comment')
                    ->label(__('admin_orders.view.manager_comment'))
                    ->maxLength(5000)
                    ->rows(5),
            ])
            ->action(function (array $data): void {
                abort_unless(Gate::allows('update', $this->record), 403);

                $comment = trim((string) ($data['manager_comment'] ?? ''));
                $this->record->forceFill(['manager_comment' => $comment === '' ? null : $comment])->save();

                Notification::make()->success()->title(__('admin_orders.actions.manager_comment_saved'))->send();
            });
    }

    /**
     * @return list<OrderStatus>
     */
    private function allowedStatuses(): array
    {
        return app(StatusTransitions::class)->allowed($this->record->type, $this->record->status);
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function afterChange(): void
    {
        app(NewRequestsCounter::class)->forget();
        $this->record->refresh()->load(['items.product', 'statusHistory.user', 'statusHistory.order']);
    }
}
