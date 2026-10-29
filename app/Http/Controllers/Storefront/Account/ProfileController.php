<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\CodeRequest;
use App\Http\Requests\Account\EmailRequest;
use App\Http\Requests\Account\ProfileRequest;
use App\Models\Customer;
use App\Services\Customers\CustomerAuthService;
use App\Settings\CustomerSettings;
use App\Support\PiiMask;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Profile (CA-08): name and phone, password change, e-mail change by a code sent to the new address. Every query is
 * about the signed-in customer only (CA-28).
 */
class ProfileController extends Controller
{
    use RendersAccountPages;

    private const NEW_EMAIL_KEY = 'customer_email_change';

    public function show(Request $request, CustomerSettings $settings): View
    {
        return $this->page('profile', __('account.profile.title'), [
            'customer' => $this->customer($request),
            'passwordMin' => $settings->password_min_length,
        ]);
    }

    public function update(ProfileRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        $auth->updateProfile($this->customer($request), (string) $request->validated('name'), $request->normalizedPhone());

        return redirect()->route('customer.profile')->with('status', __('account.profile.saved'));
    }

    public function updatePassword(ChangePasswordRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        try {
            $auth->changePassword(
                $this->customer($request),
                (string) $request->validated('current_password'),
                (string) $request->validated('password'),
                $request->session(),
            );
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        return redirect()->route('customer.profile')->with('status', __('account.profile.password_changed'));
    }

    /**
     * E-mail change, step 1: a code goes to the new address.
     */
    public function requestEmail(EmailRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        try {
            $auth->requestEmailChange($this->customer($request), $request->email(), (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->put(self::NEW_EMAIL_KEY, $request->email());

        return redirect()->route('customer.email.confirm')->with('status', __('account.profile.email_code_sent'));
    }

    public function showEmailConfirm(Request $request): View|RedirectResponse
    {
        $email = $this->newEmail($request);

        if ($email === null) {
            return redirect()->route('customer.profile');
        }

        return $this->page('email-confirm', __('account.profile.email_confirm_title'), [
            'maskedEmail' => PiiMask::email($email),
            'resendSeconds' => $this->resendSeconds($email, VerificationPurpose::ChangeEmail),
        ]);
    }

    /**
     * «Отправить код ещё раз» for the e-mail change: the new address is the one kept in the session.
     */
    public function resendEmailCode(Request $request, CustomerAuthService $auth): RedirectResponse
    {
        $email = $this->newEmail($request);

        if ($email === null) {
            return redirect()->route('customer.profile');
        }

        try {
            $auth->requestEmailChange($this->customer($request), $email, (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        return back()->with('status', __('account.resend.done'));
    }

    /**
     * E-mail change, step 2: the code from the new address.
     */
    public function confirmEmail(CodeRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        $email = $this->newEmail($request);

        if ($email === null) {
            return redirect()->route('customer.profile');
        }

        try {
            $auth->confirmEmailChange($this->customer($request), $email, $request->code(), (string) $request->ip(), $request->session());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->forget(self::NEW_EMAIL_KEY);

        return redirect()->route('customer.profile')->with('status', __('account.profile.email_changed'));
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user('customer');
        abort_unless($customer instanceof Customer, 403);

        return $customer;
    }

    private function newEmail(Request $request): ?string
    {
        $email = $request->session()->get(self::NEW_EMAIL_KEY);

        return is_string($email) && $email !== '' ? $email : null;
    }
}
