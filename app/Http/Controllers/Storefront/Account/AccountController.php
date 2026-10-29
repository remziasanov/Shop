<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Models\Customer;
use App\Services\Access\ViewerContext;
use App\Services\Customers\CustomerNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The landing page of the cabinet (CA-19). The sections (orders, addresses, company, notifications) are added to the
 * `account.sections` list of the view by the cabinet stages.
 */
class AccountController extends Controller
{
    use RendersAccountPages;

    public function index(Request $request, ViewerContext $viewer, CustomerNotificationService $notifications): View
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $this->page('index', __('account.index.title'), [
            'customer' => $customer,
            'groupName' => $viewer->group()->name,
            'unreadCount' => $notifications->unreadCount($customer),
        ]);
    }
}
