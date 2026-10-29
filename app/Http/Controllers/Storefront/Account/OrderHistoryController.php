<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Models\Order;
use App\Services\Cart\CartService;
use App\Services\Customers\CustomerOrderHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Order history of the cabinet (CA-11, CA-13, CA-28): requests of all types of the signed-in customer, a detail page
 * addressed by UUID and «Повторить заказ». A UUID of someone else's request, or of none, is a 404, never a 403.
 */
class OrderHistoryController extends Controller
{
    use RendersAccountPages;

    public function index(Request $request, CustomerOrderHistory $history): View
    {
        $customer = $this->currentCustomer($request);
        $filters = [
            'type' => $this->query($request, 'type'),
            'status' => $this->query($request, 'status'),
            'created_from' => $this->query($request, 'from'),
            'created_until' => $this->query($request, 'until'),
        ];
        $sort = $this->query($request, 'sort') === 'asc' ? 'asc' : 'desc';

        $orders = $history->paginate($customer, $filters, $sort, max(1, (int) $request->query('page', 1)));

        return $this->page('orders', __('account.orders.title'), [
            'orders' => $orders->withQueryString(),
            'types' => OrderType::options(),
            'statuses' => OrderStatus::options(),
            'filters' => [
                'type' => $filters['type'],
                'status' => $filters['status'],
                'from' => $filters['created_from'],
                'until' => $filters['created_until'],
                'sort' => $sort,
            ],
        ]);
    }

    public function show(Request $request, string $uuid, CustomerOrderHistory $history): View
    {
        $order = $this->ownOrder($request, $uuid, $history);

        return $this->page('order', $order->number, [
            'order' => $order,
            'canRepeat' => in_array($order->type, [OrderType::Order, OrderType::Reservation], true),
        ]);
    }

    /**
     * CA-13: the available positions go to the cart, the report is shown on the cart page.
     */
    public function repeat(Request $request, string $uuid, CustomerOrderHistory $history, CartService $carts): RedirectResponse
    {
        $order = $this->ownOrder($request, $uuid, $history);
        $report = $carts->addFromOrder($carts->getOrCreate($request), $order);

        return redirect()->route('cart')->with('reorder_report', $report->summary());
    }

    private function ownOrder(Request $request, string $uuid, CustomerOrderHistory $history): Order
    {
        $customer = $this->currentCustomer($request);
        $order = $history->find($customer, $uuid);

        abort_unless($order !== null && Gate::forUser($customer)->allows('viewAsCustomer', $order), 404);

        return $order;
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
