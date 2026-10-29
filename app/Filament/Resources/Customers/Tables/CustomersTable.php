<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\OrderStatus;
use App\Exceptions\CustomerAccountException;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Customers\CustomerAdminService;
use App\Services\Orders\OrderQuery;
use App\Support\Money;
use Carbon\Carbon;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * List of customers (CA-20): name, e-mail, phone, group, confirmation, number and sum of completed requests,
 * registration and last sign-in, block; search by name, e-mail and phone; filters; bulk change of group (CA-21).
 */
final class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('group')
                ->withCount('orders')
                ->withSum(['orders as purchased_total' => fn (Builder $orders) => $orders->where('status', OrderStatus::Completed->value)], 'total'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('customers.table.name'))
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('customers.table.email'))
                    ->copyable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('customers.table.phone'))
                    ->placeholder('—'),
                TextColumn::make('group.name')
                    ->label(__('customers.table.group'))
                    ->badge()
                    ->color('gray'),
                IconColumn::make('email_verified_at')
                    ->label(__('customers.table.verified'))
                    ->boolean()
                    ->state(fn (Customer $record): bool => $record->email_verified_at !== null),
                TextColumn::make('orders_count')
                    ->label(__('customers.table.orders'))
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('purchased_total')
                    ->label(__('customers.table.purchased'))
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn (mixed $state): string => Money::format(Money::fromRubles(is_numeric($state) ? (string) $state : '0'))),
                TextColumn::make('created_at')
                    ->label(__('customers.table.registered'))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('last_login_at')
                    ->label(__('customers.table.last_login'))
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                IconColumn::make('is_blocked')
                    ->label(__('customers.table.blocked'))
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('gray'),
            ])
            ->searchUsing(fn (Builder $query, string $search) => self::search($query, $search))
            ->filters([
                SelectFilter::make('customer_group_id')
                    ->label(__('customers.filters.group'))
                    ->options(fn (): array => CustomerGroup::query()->ordered()->pluck('name', 'id')->all()),
                TernaryFilter::make('verified')
                    ->label(__('customers.filters.verified'))
                    ->placeholder(__('customers.filters.all'))
                    ->trueLabel(__('customers.filters.yes'))
                    ->falseLabel(__('customers.filters.no'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('email_verified_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('email_verified_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                TernaryFilter::make('is_blocked')
                    ->label(__('customers.filters.blocked'))
                    ->placeholder(__('customers.filters.all'))
                    ->trueLabel(__('customers.filters.yes'))
                    ->falseLabel(__('customers.filters.no')),
                TernaryFilter::make('has_orders')
                    ->label(__('customers.filters.has_orders'))
                    ->placeholder(__('customers.filters.all'))
                    ->trueLabel(__('customers.filters.yes'))
                    ->falseLabel(__('customers.filters.no'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereHas('orders'),
                        false: fn (Builder $query): Builder => $query->whereDoesntHave('orders'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                Filter::make('registered')
                    ->label(__('customers.filters.registered'))
                    ->schema([
                        DatePicker::make('registered_from')->label(__('customers.filters.registered_from')),
                        DatePicker::make('registered_until')->label(__('customers.filters.registered_until')),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['registered_from'] ?? null), fn (Builder $q): Builder => $q->where('customers.created_at', '>=', Carbon::parse($data['registered_from'])->startOfDay()))
                        ->when(filled($data['registered_until'] ?? null), fn (Builder $q): Builder => $q->where('customers.created_at', '<=', Carbon::parse($data['registered_until'])->endOfDay())))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if (filled($data['registered_from'] ?? null)) {
                            $indicators[] = __('customers.filters.indicator_from', ['date' => Carbon::parse($data['registered_from'])->format('d.m.Y')]);
                        }

                        if (filled($data['registered_until'] ?? null)) {
                            $indicators[] = __('customers.filters.indicator_until', ['date' => Carbon::parse($data['registered_until'])->format('d.m.Y')]);
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::changeGroupBulkAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Customer $record): string => CustomerResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('customers.table.empty_heading'))
            ->emptyStateDescription(__('customers.table.empty_description'));
    }

    /**
     * Name, e-mail and phone; a phone is matched by digits, however it is typed.
     *
     * @param  Builder<Customer>  $query
     */
    public static function search(Builder $query, string $term): void
    {
        $term = trim(str_replace(['\\', '%', '_'], '', $term));

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';
        $phone = OrderQuery::phoneDigits($term);

        $query->where(function (Builder $query) use ($like, $phone): void {
            $query->where('customers.name', 'like', $like)->orWhere('customers.email', 'like', $like);

            if ($phone !== null) {
                $query->orWhere('customers.phone', 'like', '%'.$phone.'%');
            }
        });
    }

    /**
     * «Сменить группу» for the selected customers (CA-21). The ability is checked again by the service.
     */
    private static function changeGroupBulkAction(): BulkAction
    {
        return BulkAction::make('change_group')
            ->label(__('customers.actions.change_group_bulk'))
            ->icon('heroicon-o-user-group')
            ->visible(fn (): bool => Gate::allows('changeGroupAny', Customer::class))
            ->schema([
                Select::make('group_id')
                    ->label(__('customers.actions.group'))
                    ->options(fn (): array => self::assignableGroups())
                    ->required()
                    ->native(false),
            ])
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records, array $data): void {
                $actor = Auth::user();
                $group = CustomerGroup::query()->find((int) $data['group_id']);

                if (! $actor instanceof User || $group === null) {
                    return;
                }

                try {
                    $count = app(CustomerAdminService::class)->changeGroupMany($records, $group, $actor);
                } catch (AuthorizationException) {
                    Notification::make()->danger()->title(__('customers.notifications.forbidden'))->send();

                    return;
                } catch (CustomerAccountException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__('customers.notifications.groups_changed', ['count' => $count]))->send();
            });
    }

    /**
     * Groups a customer can be put in: everything except the system group «Гость».
     *
     * @return array<int, string>
     */
    public static function assignableGroups(): array
    {
        return CustomerGroup::query()->where('code', '!=', CustomerGroup::GUEST)->ordered()->pluck('name', 'id')->all();
    }
}
