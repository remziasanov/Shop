<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Exceptions\FormGuardException;
use App\Exceptions\WholesaleApplicationException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\WholesaleApplicationRequest;
use App\Models\Customer;
use App\Services\Customers\WholesaleApplicationService;
use App\Services\Sales\FormGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Стать оптовым покупателем» (CG-20): the application form of a signed-in customer, the status of the last
 * application, an invitation to register for a guest. Every query is about the signed-in customer only (CA-28).
 */
class WholesaleApplicationController extends Controller
{
    use RendersAccountPages;

    public function show(Request $request, FormGuard $guard, WholesaleApplicationService $applications): View
    {
        $customer = $request->user('customer');
        $title = __('wholesale.page.title');

        if (! $customer instanceof Customer) {
            return $this->page('wholesale', $title, ['customer' => null]);
        }

        $blockReason = $applications->applyBlockReason($customer);

        return $this->page('wholesale', $title, [
            'customer' => $customer,
            'latest' => $applications->latestOf($customer)?->loadMissing('decidedGroup'),
            'blockReason' => $blockReason,
            'formToken' => $blockReason === null ? $guard->issueToken() : null,
            'consentLink' => $this->consentLink(),
        ]);
    }

    public function store(WholesaleApplicationRequest $request, FormGuard $guard, WholesaleApplicationService $applications): RedirectResponse
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        // CG-24: honeypot, fill time and the per-IP limit of the request forms.
        try {
            $guard->check($request->input('website'), $request->input('form_token'), (string) $request->ip());
        } catch (FormGuardException $e) {
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        try {
            $applications->submit($customer, $request->applicationData(), $request->ip(), $request->userAgent());
        } catch (WholesaleApplicationException $e) {
            return back()->withInput()->withErrors(['form' => $e->getMessage()]);
        }

        return redirect()->route('customer.wholesale')->with('status', __('wholesale.form.sent'));
    }
}
