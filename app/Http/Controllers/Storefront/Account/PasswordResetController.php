<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\CodeRequest;
use App\Http\Requests\Account\EmailRequest;
use App\Http\Requests\Account\NewPasswordRequest;
use App\Services\Customers\CustomerAuthService;
use App\Settings\CustomerSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Password reset by an e-mail code (CA-07): address, code, new password. No token links.
 */
class PasswordResetController extends Controller
{
    use RendersAccountPages;

    private const EMAIL_KEY = 'customer_reset_email';

    public function showRequest(): View
    {
        return $this->page('forgot', __('account.reset.title'));
    }

    /**
     * Step 1. The same answer with and without an account (CA-05).
     */
    public function sendCode(EmailRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        try {
            $auth->requestPasswordReset($request->email(), (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->put(self::EMAIL_KEY, $request->email());

        return redirect()->route('customer.password.code')->with('status', __('account.reset.code_sent'));
    }

    public function showCode(Request $request): View|RedirectResponse
    {
        $email = $this->email($request);

        if ($email === null) {
            return redirect()->route('customer.password.forgot');
        }

        return $this->page('forgot-code', __('account.reset.code_title'), [
            'resendSeconds' => $this->resendSeconds($email, VerificationPurpose::PasswordReset),
        ]);
    }

    /**
     * «Отправить код ещё раз» on the code page; the service enforces the pause.
     */
    public function resendCode(Request $request, CustomerAuthService $auth): RedirectResponse
    {
        $email = $this->email($request);

        if ($email === null) {
            return redirect()->route('customer.password.forgot');
        }

        try {
            $auth->requestPasswordReset($email, (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        return back()->with('status', __('account.resend.done'));
    }

    /**
     * Step 2: the right code opens the new-password form for 10 minutes, once.
     */
    public function verifyCode(CodeRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        $email = $this->email($request);

        if ($email === null) {
            return redirect()->route('customer.password.forgot');
        }

        try {
            $auth->verifyPasswordResetCode($email, $request->code(), (string) $request->ip(), $request->session());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->forget(self::EMAIL_KEY);

        return redirect()->route('customer.password.reset');
    }

    public function showReset(Request $request, CustomerAuthService $auth, CustomerSettings $settings): View|RedirectResponse
    {
        if ($auth->resetTicketEmail($request->session()) === null) {
            return redirect()->route('customer.password.forgot')->withErrors(['email' => __('account.errors.reset_expired')]);
        }

        return $this->page('reset', __('account.reset.new_title'), ['passwordMin' => $settings->password_min_length]);
    }

    /**
     * Step 3: the new password; the other sessions end and this browser is signed in.
     */
    public function reset(NewPasswordRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        try {
            $customer = $auth->resetPassword($request->session(), (string) $request->validated('password'));
        } catch (CustomerAuthException $e) {
            return redirect()->route('customer.password.forgot')->withErrors([$e->field => $e->getMessage()]);
        }

        $auth->signIn($customer, $request);

        return redirect()->route('account')->with('status', __('account.reset.done'));
    }

    private function email(Request $request): ?string
    {
        $email = $request->session()->get(self::EMAIL_KEY);

        return is_string($email) && $email !== '' ? $email : null;
    }
}
