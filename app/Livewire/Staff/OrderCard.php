<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Enums\StaffOrderAction;
use App\Enums\TelegramMessageKind;
use App\Livewire\Staff\Concerns\RequiresTelegramStaff;
use App\Models\Order;
use App\Services\Telegram\Staff\StaffActionResult;
use App\Services\Telegram\Staff\StaffMessageSync;
use App\Services\Telegram\Staff\StaffOrderActions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The card of a request in the Mini App «Рабочее место» (TS-06): items, sum, customer, «Позвонить», the customer comment,
 * the status history, the Telegram messages, and the actions of the staff (status, reservation extension, manager
 * comment). Every action goes through {@see StaffOrderActions}: rights on the server (NF-54, TS-07), the version the card was
 * drawn with, the journal with the source `telegram`. No editing of items or prices, no deletion, no export: «Открыть в
 * админке» opens the full panel in the browser.
 */
class OrderCard extends Component
{
    use RequiresTelegramStaff;

    #[Locked]
    public int $orderId;

    /** The version of the request the staff member sees; an action with an older one changes nothing. */
    #[Locked]
    public int $version = 0;

    public int|string $extendHours = StaffOrderAction::EXTEND_HOURS;

    public string $managerComment = '';

    public ?string $notice = null;

    public bool $noticeError = false;

    /** The dangerous action whose first tap happened (the button asks «Точно отменить?»). */
    public ?string $armed = null;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $order = Order::query()->find($orderId);
        abort_if($order === null, 404);

        $this->version = $order->version;
        $this->managerComment = (string) $order->manager_comment;
    }

    public function act(string $code): void
    {
        $staff = $this->staffUser();
        $action = StaffOrderAction::tryFrom($code);

        if ($action === null) {
            return;
        }

        $hours = $action === StaffOrderAction::Extend ? (int) $this->extendHours : StaffOrderAction::EXTEND_HOURS;
        $result = app(StaffOrderActions::class)->perform($staff, $this->orderId, $action, $this->version, 'workplace', $hours);

        $this->armed = $result->outcome === StaffActionResult::ARMED ? $action->value : null;
        $this->report($result);

        if ($result->order !== null) {
            $this->version = $result->order->version;

            if ($result->isDone()) {
                $this->managerComment = (string) $result->order->manager_comment;
                // The messages in the chats keep their buttons in step with the request (queue jobs, NF-60).
                app(StaffMessageSync::class)->redrawCopies($result->order);
            }
        }
    }

    public function saveComment(): void
    {
        $staff = $this->staffUser();
        $comment = trim(mb_substr($this->managerComment, 0, 5000));
        $result = app(StaffOrderActions::class)->saveManagerComment($staff, $this->orderId, $comment === '' ? null : $comment);

        $this->armed = null;

        if ($result->isDone() && $result->order !== null) {
            $this->version = $result->order->version;
            $this->notice = __('telegram_staff.workplace.manager_comment_saved');
            $this->noticeError = false;

            return;
        }

        $this->report($result);
    }

    public function render(): View
    {
        $staff = $this->staffUser();
        $order = Order::query()->with(['items', 'statusHistory.user:id,name'])->find($this->orderId);
        abort_if($order === null, 404);

        $canUpdate = $this->canUpdate($staff);

        return view('livewire.staff.order-card', [
            'order' => $order,
            'canUpdate' => $canUpdate,
            'actions' => $canUpdate ? app(StaffOrderActions::class)->available($order) : [],
            'messages' => $order->telegramMessages()
                ->whereNotIn('kind', [TelegramMessageKind::StaffNewOrder->value, TelegramMessageKind::StaffOrderAction->value])
                ->latest('id')
                ->limit(10)
                ->get(['id', 'kind', 'status', 'created_at']),
            'adminUrl' => route('filament.admin.resources.orders.view', ['record' => $order->uuid]),
        ]);
    }

    private function report(StaffActionResult $result): void
    {
        $this->noticeError = ! in_array($result->outcome, [StaffActionResult::DONE, StaffActionResult::ARMED], true);
        $this->notice = match ($result->outcome) {
            StaffActionResult::DONE => __('telegram_staff.toast.done_default'),
            StaffActionResult::ARMED => __('telegram_staff.toast.confirm_cancel'),
            StaffActionResult::STALE => __('telegram_staff.toast.stale'),
            StaffActionResult::FORBIDDEN => __('telegram_staff.toast.forbidden'),
            StaffActionResult::NOT_FOUND => __('telegram_staff.toast.not_found'),
            default => $result->message ?? __('telegram_staff.toast.failed'),
        };
    }
}
