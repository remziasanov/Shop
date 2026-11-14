<?php

declare(strict_types=1);

namespace App\Services\Telegram\Staff;

use App\Enums\AuditAction;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\StaffOrderAction;
use App\Enums\StatusChangeSource;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\SalesException;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\NewRequestsCounter;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderService;
use App\Services\Orders\StatusTransitions;
use App\Services\Users\AuditLogger;
use App\Services\Users\PermissionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Staff actions on a request from Telegram (TS-03, TS-04, TS-07): the buttons under the work chat messages and the
 * «Рабочее место». Nothing is decided here that the admin panel decides differently: the change goes through
 * {@see OrderService} (transitions of Appendix B, stock through StockService under lock, history, events) with the source
 * `telegram`, the right is the same `orders.update` of {@see PermissionService}, checked on every call for the user passed
 * explicitly (never the default guard). Every success is written to `admin_audit_log` with the source `telegram`.
 *
 * `$expectedVersion` makes a button idempotent: a request moved since the button was drawn changes nothing (TS-03, TS-04).
 */
final class StaffOrderActions
{
    /** Seconds the second tap of a dangerous action may come after the first (TS-04). The window is server time only. */
    public const CONFIRM_WINDOW_SECONDS = 30;

    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderEditor $editor,
        private readonly StatusTransitions $transitions,
        private readonly PermissionService $permissions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Actions that fit the request right now, in button order: only transitions of Appendix B (the system-only `expired`
     * and `completed` handovers are not offered here).
     *
     * @return list<StaffOrderAction>
     */
    public function available(Order $order): array
    {
        $actions = [];

        foreach (StaffOrderAction::cases() as $action) {
            if ($action === StaffOrderAction::Extend) {
                if ($order->type === OrderType::Reservation && in_array($order->status, [OrderStatus::New, OrderStatus::Reserved], true)) {
                    $actions[] = $action;
                }

                continue;
            }

            $to = $action->targetStatus($order->type);

            if ($to !== null && $this->transitions->canTransition($order->type, $order->status, $to)) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * @param  string  $via  `chat` (a button) or `workplace`, written to the audit entry
     * @param  int  $hours  the extension of a reservation (1…2160, the limit of the admin form); the chat button always sends 24
     */
    public function perform(User $staff, int $orderId, StaffOrderAction $action, ?int $expectedVersion, string $via, int $hours = StaffOrderAction::EXTEND_HOURS): StaffActionResult
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return new StaffActionResult(StaffActionResult::NOT_FOUND, null, $action);
        }

        // NF-54: the right is read on every call; an inactive user or a user without a role has none.
        if (! $this->permissions->allows($staff, 'orders.update')) {
            return new StaffActionResult(StaffActionResult::FORBIDDEN, $order, $action);
        }

        if ($expectedVersion !== null && $order->version !== $expectedVersion) {
            return new StaffActionResult(StaffActionResult::STALE, $order, $action);
        }

        if (! in_array($action, $this->available($order), true)) {
            return new StaffActionResult(StaffActionResult::STALE, $order, $action);
        }

        if ($action->isDangerous() && ! $this->confirmed($staff, $order, $action)) {
            return new StaffActionResult(StaffActionResult::ARMED, $order, $action);
        }

        $hours = max(1, min(24 * 90, $hours));

        try {
            $from = $order->status;
            $fresh = $this->apply($staff, $order, $action, $expectedVersion, $hours);
        } catch (StaleRequest) {
            return new StaffActionResult(StaffActionResult::STALE, $order->refresh(), $action);
        } catch (InvalidOrderTransitionException) {
            return new StaffActionResult(StaffActionResult::STALE, $order->refresh(), $action);
        } catch (SalesException $e) {
            return new StaffActionResult(StaffActionResult::FAILED, $order->refresh(), $action, $e->getMessage());
        }

        $this->record($staff, $fresh, $action, $from, $via, $hours);
        app(NewRequestsCounter::class)->forget();

        return new StaffActionResult(StaffActionResult::DONE, $fresh, $action);
    }

    /**
     * The manager comment (TS-06), with the rights and the journal of the other actions.
     */
    public function saveManagerComment(User $staff, int $orderId, ?string $comment): StaffActionResult
    {
        $order = Order::query()->find($orderId);

        if ($order === null) {
            return new StaffActionResult(StaffActionResult::NOT_FOUND);
        }

        if (! $this->permissions->allows($staff, 'orders.update')) {
            return new StaffActionResult(StaffActionResult::FORBIDDEN, $order);
        }

        try {
            $changed = $this->editor->saveManagerComment($order->id, $comment, $staff);
        } catch (SalesException $e) {
            return new StaffActionResult(StaffActionResult::FAILED, $order, null, $e->getMessage());
        }

        if ($changed) {
            $this->audit->record(AuditAction::OrderManagerCommentChanged, $staff, $order, ['via' => 'workplace'], StatusChangeSource::Telegram);
        }

        return new StaffActionResult(StaffActionResult::DONE, $order->refresh());
    }

    /**
     * TS-04: the first tap stores «request + staff member» for 30 seconds and returns false; the second one inside
     * the window consumes the key and returns true. `Cache::add` makes two simultaneous first taps one.
     */
    private function confirmed(User $staff, Order $order, StaffOrderAction $action): bool
    {
        $key = self::confirmKey($staff, $order, $action);

        if (Cache::pull($key) !== null) {
            return true;
        }

        Cache::put($key, 1, self::CONFIRM_WINDOW_SECONDS);

        return false;
    }

    public static function confirmKey(User $staff, Order $order, StaffOrderAction $action): string
    {
        return 'tg-staff-confirm:'.$action->value.':'.$order->id.':'.$staff->id;
    }

    /**
     * One transaction: lock the request, check the version once more under the lock (two taps at the same moment must
     * not both pass), then the same service call as the admin panel.
     */
    private function apply(User $staff, Order $order, StaffOrderAction $action, ?int $expectedVersion, int $hours): Order
    {
        return DB::transaction(function () use ($staff, $order, $action, $expectedVersion, $hours): Order {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
                throw new StaleRequest;
            }

            if ($action === StaffOrderAction::Extend) {
                return $this->orders->extendReservation($locked, $hours, $staff, StatusChangeSource::Telegram);
            }

            $to = $action->targetStatus($locked->type) ?? throw new StaleRequest;

            return $this->orders->changeStatus($locked, $to, $staff, null, null, StatusChangeSource::Telegram);
        });
    }

    private function record(User $staff, Order $order, StaffOrderAction $action, OrderStatus $from, string $via, int $hours): void
    {
        if ($action === StaffOrderAction::Extend) {
            $this->audit->record(AuditAction::OrderReservationExtended, $staff, $order, [
                'hours' => $hours,
                'via' => $via,
            ], StatusChangeSource::Telegram);

            return;
        }

        $this->audit->record(AuditAction::OrderStatusChanged, $staff, $order, [
            'from' => $from->value,
            'to' => $order->status->value,
            'via' => $via,
        ], StatusChangeSource::Telegram);
    }
}
