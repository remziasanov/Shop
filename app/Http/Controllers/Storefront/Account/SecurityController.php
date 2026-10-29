<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\CodeRequest;
use App\Services\Customers\AccountDeletionService;
use App\Services\Customers\CustomerAuthService;
use App\Settings\CustomerSettings;
use App\Support\PiiMask;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Безопасность» (CA-16): deleting the account, confirmed by a code from the mail. The deletion is a request: the
 * account is closed for sign-in, every session ends, and the data are anonymized after the cancel period.
 */
class SecurityController extends Controller
{
    use RendersAccountPages;

    public function show(CustomerSettings $settings): View
    {
        return $this->page('security', __('account.security.title'), [
            'graceDays' => $settings->deletion_grace_days,
        ]);
    }

    /**
     * Step 1: a code goes to the address of the customer.
     */
    public function requestDeletion(Request $request, AccountDeletionService $deletion): RedirectResponse
    {
        try {
            $deletion->requestCode($this->currentCustomer($request), (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        return redirect()->route('customer.security.delete')->with('status', __('account.security.code_sent'));
    }

    public function showConfirm(Request $request, CustomerSettings $settings): View
    {
        $customer = $this->currentCustomer($request);

        return $this->page('delete-confirm', __('account.security.confirm_title'), [
            'maskedEmail' => PiiMask::email($customer->email),
            'graceDays' => $settings->deletion_grace_days,
            'resendSeconds' => $this->resendSeconds($customer->email, VerificationPurpose::DeleteAccount),
        ]);
    }

    /**
     * «Отправить код ещё раз» for the deletion code; the service enforces the pause.
     */
    public function resendDeletionCode(Request $request, AccountDeletionService $deletion): RedirectResponse
    {
        try {
            $deletion->requestCode($this->currentCustomer($request), (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        return back()->with('status', __('account.resend.done'));
    }

    /**
     * Step 2: the right code requests the deletion and signs the customer out everywhere.
     */
    public function confirmDeletion(CodeRequest $request, AccountDeletionService $deletion, CustomerAuthService $auth): RedirectResponse
    {
        try {
            $deletion->confirm($this->currentCustomer($request), $request->code(), (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $auth->signOut($request);

        return redirect()->route('customer.login')->with('status', __('account.security.requested'));
    }
}
