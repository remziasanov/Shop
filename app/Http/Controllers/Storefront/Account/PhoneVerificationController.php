<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Services\PhoneVerification\PhoneChallenge;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * `/account/phone` (PV-07, PV-13): the page where a signed-in customer verifies the phone of the registration, a new
 * profile phone or the phone of a wholesale application. The work is done by the Livewire panel on it.
 */
class PhoneVerificationController extends Controller
{
    use RendersAccountPages;

    public function show(Request $request, PhoneChallenge $challenges): View|RedirectResponse
    {
        $customer = $this->currentCustomer($request);

        if ($challenges->current($request->session(), $customer) === null) {
            return redirect()->route('customer.profile');
        }

        return $this->page('phone', __('phone_verification.screen.page_title'));
    }
}
