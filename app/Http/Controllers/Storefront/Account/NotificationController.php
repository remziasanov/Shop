<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Models\CustomerNotification;
use App\Services\Customers\CustomerNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Cabinet notifications (CA-18). Reading marks a notification as read; a notification of another customer is a 404.
 */
class NotificationController extends Controller
{
    use RendersAccountPages;

    private const PER_PAGE = 20;

    public function index(Request $request, CustomerNotificationService $service): View
    {
        $customer = $this->currentCustomer($request);

        return $this->page('notifications', __('account.notifications.title'), [
            'unreadTotal' => $service->unreadCount($customer),
            'notifications' => CustomerNotification::query()
                ->where('customer_id', $customer->id)
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE),
        ]);
    }

    /**
     * Marks one notification as read and goes to the page it is about (if any).
     */
    public function read(Request $request, int $notification, CustomerNotificationService $notifications): RedirectResponse
    {
        $customer = $this->currentCustomer($request);
        $row = CustomerNotification::query()->where('customer_id', $customer->id)->whereKey($notification)->first() ?? abort(404);

        $notifications->markRead($customer, $row->id);

        // Only a path of this shop is followed.
        $url = $row->url;

        return is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            ? redirect($url)
            : redirect()->route('customer.notifications');
    }

    public function readAll(Request $request, CustomerNotificationService $notifications): RedirectResponse
    {
        $notifications->markAllRead($this->currentCustomer($request));

        return redirect()->route('customer.notifications')->with('status', __('account.notifications.all_read'));
    }
}
