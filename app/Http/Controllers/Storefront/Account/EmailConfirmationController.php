<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\CodeRequest;
use App\Services\Customers\CustomerAuthService;
use App\Support\PiiMask;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Confirmation of the e-mail address by the registration code (CA-03, CA-04): the account is usable after it, and the
 * customer is signed in.
 */
class EmailConfirmationController extends Controller
{
    use RendersAccountPages;

    public function show(Request $request): View|RedirectResponse
    {
        $email = $this->pendingEmail($request);

        if ($email === null) {
            return redirect()->route('customer.register');
        }

        return $this->page('confirm', __('account.confirm.title'), [
            'maskedEmail' => PiiMask::email($email),
            'resendSeconds' => $this->resendSeconds($email, VerificationPurpose::Register),
        ]);
    }

    public function store(CodeRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        $email = $this->pendingEmail($request);

        if ($email === null) {
            return redirect()->route('customer.register');
        }

        try {
            $customer = $auth->confirmRegistration($email, $request->code(), (string) $request->ip(), RegisterController::bindingToken($request));
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->forget([RegisterController::PENDING_EMAIL_KEY, RegisterController::BINDING_KEY]);
        $auth->signIn($customer, $request);

        return redirect()->intended(route('account'))->with('status', __('account.confirm.done'));
    }

    public function resend(Request $request, CustomerAuthService $auth): RedirectResponse
    {
        $email = $this->pendingEmail($request);

        if ($email === null) {
            return redirect()->route('customer.register');
        }

        try {
            $auth->resendRegistrationCode($email, (string) $request->ip(), RegisterController::bindingToken($request));
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        return back()->with('status', __('account.confirm.resent'));
    }

    private function pendingEmail(Request $request): ?string
    {
        $email = $request->session()->get(RegisterController::PENDING_EMAIL_KEY);

        return is_string($email) && $email !== '' ? $email : null;
    }
}
