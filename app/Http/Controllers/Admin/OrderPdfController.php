<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Services\Export\OrderPdfRenderer;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Печать / скачать PDF» of the order card (CX-13): the document template of CX-10 (invoice when the seller's bank
 * requisites are filled, otherwise a blank) for a staff member who may view the order. The browser shows it inline,
 * so it can be printed or saved.
 */
final class OrderPdfController
{
    public function __invoke(Order $order, OrderPdfRenderer $renderer): Response
    {
        Gate::authorize('view', $order);

        $name = 'order-'.(preg_replace('/[^A-Za-z0-9._-]+/', '_', $order->number) ?? 'order').'.pdf';

        return response($renderer->document($order), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
