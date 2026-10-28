<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Exceptions\CustomerAuthException;
use App\Exceptions\CustomerNotVerifiedException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\CodeRequest;
use App\Http\Requests\Account\EmailRequest;
use App\Http\Requests\Account\LoginRequest;
use App\Services\Customers\CustomerAuthService;
use App\Settings\CustomerSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Sign-in by password or by a one-time code (CA-06). The route `customer.login` is the target of every «Войдите» link
 * and of the redirects of hidden sections (CG-04, CG-06).
 */
class LoginController extends Controller
{
    use RendersAccountPages;

    private const CODE_EMAIL_KEY = 'customer_login_email';

    public function show(CustomerSettings $settings): View
    {
        return $this->page('login', __('account.login.title'), ['registrationEnabled' => $settings->registration_enabled]);
    }

    public function store(LoginRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        try {
            $customer = $auth->authenticateByPassword($request->email(), (string) $request->validated('password'), (string) $request->ip());
        } catch (CustomerNotVerifiedException $e) {
            $request->session()->put(RegisterController::PENDING_EMAIL_KEY, $e->email);

            return redirect()->route('customer.confirm')->with('status', $e->getMessage());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $auth->signIn($customer, $request, $request->boolean('remember'));

        return redirect()->intended(route('account'));
    }

    /**
     * «Войти по коду», step 1. The answer is the same whether or not the address has an account (CA-05).
     */
    public function sendCode(EmailRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        try {
            $auth->requestLoginCode($request->email(), (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->put(self::CODE_EMAIL_KEY, $request->email());

        return redirect()->route('customer.login.code')->with('status', __('account.login.code_sent'));
    }

    public function showCode(Request $request): View|RedirectResponse
    {
        $email = $this->codeEmail($request);

        if ($email === null) {
            return redirect()->route('customer.login');
        }

        return $this->page('login-code', __('account.login.code_title'));
    }

    public function verifyCode(CodeRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        $email = $this->codeEmail($request);

        if ($email === null) {
            return redirect()->route('customer.login');
        }

        try {
            $customer = $auth->authenticateByCode($email, $request->code(), (string) $request->ip());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->forget(self::CODE_EMAIL_KEY);
        $auth->signIn($customer, $request, $request->boolean('remember'));

        return redirect()->intended(route('account'));
    }

    private function codeEmail(Request $request): ?string
    {
        $email = $request->session()->get(self::CODE_EMAIL_KEY);

        return is_string($email) && $email !== '' ? $email : null;
    }
}
