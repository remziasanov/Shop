<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Livewire\Staff\Concerns\RequiresTelegramStaff;
use App\Models\Order;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The list of the Mini App «Рабочее место» (TS-06): tabs «Новые», «В работе», «Резервы: истекают сегодня», «Все» and the
 * search by number, phone and name. Read-only; the actions live on the card. One page of 20 requests per tap of «Показать
 * ещё»; only the columns the list shows are read (NF-02: at most 30 SQL statements).
 */
class WorkplaceList extends Component
{
    use RequiresTelegramStaff;

    public const TABS = ['new', 'active', 'expiring', 'all'];

    public const PAGE = 20;

    #[Url(as: 'tab')]
    public string $tab = 'new';

    public string $search = '';

    public const MAX_LIMIT = 100;

    /** Server-controlled page size: the client cannot set it (a Livewire request must not load the whole table). */
    #[Locked]
    public int $limit = self::PAGE;

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'new';
        $this->limit = self::PAGE;
    }

    public function updatedSearch(): void
    {
        $this->limit = self::PAGE;
    }

    public function more(): void
    {
        $this->limit = min(self::MAX_LIMIT, $this->limit + self::PAGE);
    }

    public function render(): View
    {
        $this->staffUser();

        $tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'new';
        $query = $this->query($tab);
        $limit = min(self::MAX_LIMIT, max(self::PAGE, $this->limit));
        $rows = $query->limit($limit + 1)->get();

        return view('livewire.staff.workplace-list', [
            'tab' => $tab,
            'orders' => $rows->take($limit),
            'hasMore' => $rows->count() > $limit,
            'newCount' => Order::query()->new()->count(),
        ]);
    }

    /**
     * @return Builder<Order>
     */
    private function query(string $tab): Builder
    {
        $query = Order::query()->select(['id', 'number', 'type', 'status', 'customer_name', 'customer_phone', 'total', 'reserved_until', 'created_at']);

        match ($tab) {
            'new' => $query->where('status', OrderStatus::New->value)->orderByDesc('id'),
            'active' => $query
                ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Ready->value, OrderStatus::Shipped->value, OrderStatus::Reserved->value, OrderStatus::InProgress->value])
                ->orderByDesc('id'),
            // Active reservations whose term ends before midnight (overdue ones that the scheduler has not expired yet included).
            'expiring' => $query
                ->where('type', OrderType::Reservation->value)
                ->whereIn('status', [OrderStatus::New->value, OrderStatus::Reserved->value])
                ->where('reserved_until', '<=', now()->endOfDay())
                ->orderBy('reserved_until'),
            default => $query->orderByDesc('id'),
        };

        $term = trim($this->search);

        if ($term !== '') {
            $like = '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';
            $digits = preg_replace('/\D+/', '', $term) ?? '';
            // Phones are stored as +7XXXXXXXXXX: a whole number is matched in that form, a part of one by its digits.
            $phone = PhoneNormalizer::normalize($term);

            $query->where(function (Builder $q) use ($like, $digits, $phone): void {
                $q->where('number', 'like', $like)->orWhere('customer_name', 'like', $like);

                if ($phone !== null) {
                    $q->orWhere('customer_phone', $phone);
                } elseif (strlen($digits) >= 3) {
                    $q->orWhere('customer_phone', 'like', '%'.$digits.'%');
                }
            });
        }

        return $query;
    }
}
