<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Telegram;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTelegramStaff;
use App\Models\Order;
use Illuminate\Contracts\View\View;

/**
 * The pages of the Mini App «Рабочее место» (TS-05, TS-06). The route middleware {@see EnsureTelegramStaff}
 * has checked the staff member; the Livewire components check again on every action.
 */
class StaffWorkplaceController extends Controller
{
    public function home(): View
    {
        return view('storefront.telegram.staff.home');
    }

    public function order(int $order): View
    {
        abort_unless(Order::query()->whereKey($order)->exists(), 404);

        return view('storefront.telegram.staff.order', ['orderId' => $order]);
    }
}
