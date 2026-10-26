<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Settings\GeneralSettings;
use App\Settings\LegalSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Printable request blank without the admin chrome (OR-06). Staff only.
 */
final class OrderPrintController
{
    public function __invoke(Order $order, GeneralSettings $general, LegalSettings $legal): View
    {
        Gate::authorize('view', $order);

        return view('admin.orders.print', [
            'order' => $order->load('items'),
            'general' => $general,
            'legal' => $legal,
        ]);
    }
}
