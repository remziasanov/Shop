<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Exceptions\ExportThrottledException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Services\Customers\CustomerOrderHistory;
use App\Services\Export\CustomerExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Скачать бланк заказа (PDF)» (CX-10): the order as «Счёт на оплату» or «Бланк заказа». Only for a group with the
 * `allow_order_blank` flag: a direct URL of any other group is a 403. Someone else's order is a 404.
 */
class OrderBlankController extends Controller
{
    use RendersAccountPages;

    public function __invoke(Request $request, string $uuid, CustomerOrderHistory $history, CustomerExportService $exports): Response
    {
        $customer = $this->currentCustomer($request);
        $order = $history->find($customer, $uuid);

        abort_unless($order !== null && Gate::forUser($customer)->allows('viewAsCustomer', $order), 404);
        abort_unless($customer->group?->allow_order_blank === true, 403);
        // An inquiry has no prices: there is nothing to put on a blank.
        abort_if($order->total === null, 404);

        try {
            $exports->hitDownload($customer);
        } catch (ExportThrottledException $e) {
            abort(429, $e->getMessage(), ['Retry-After' => (string) $e->retryAfter]);
        }

        $name = 'order-'.(preg_replace('/[^A-Za-z0-9._-]+/', '_', $order->number) ?? 'order').'.pdf';

        return response()->streamDownload(function () use ($exports, $order): void {
            echo $exports->orderDocument($order);
        }, $name, ['Content-Type' => 'application/pdf']);
    }
}
