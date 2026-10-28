<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Exceptions\CustomerAuthException;
use App\Exceptions\FormGuardException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\RegisterRequest;
use App\Services\Customers\CustomerAuthService;
use App\Services\Sales\FormGuard;
use App\Settings\CustomerSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Registration (CA-02, CA-03, CA-05): the form, then the confirmation code page.
 */
class RegisterController extends Controller
{
    use RendersAccountPages;

    public const PENDING_EMAIL_KEY = 'customer_pending_email';

    public function show(Request $request, FormGuard $guard, CustomerSettings $settings): View
    {
        abort_unless($settings->registration_enabled, 404);

        // CA-27: the «Спасибо» page links here with the e-mail of the guest order.
        $email = $request->query('email');

        return $this->page('register', __('account.register.title'), [
            'formToken' => $guard->issueToken(),
            'consentLink' => $this->consentLink(),
            'prefillEmail' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'passwordMin' => $settings->password_min_length,
        ]);
    }

    public function store(RegisterRequest $request, FormGuard $guard, CustomerAuthService $auth, CustomerSettings $settings): RedirectResponse
    {
        abort_unless($settings->registration_enabled, 404);

        try {
            $guard->checkSpam($request->input('website'), $request->input('form_token'));
        } catch (FormGuardException $e) {
            return back()->withInput($request->except(['password', 'password_confirmation']))->withErrors(['form' => $e->getMessage()]);
        }

        try {
            $auth->register($request->registrationData());
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->put(self::PENDING_EMAIL_KEY, $request->email());

        return redirect()->route('customer.confirm')->with('status', __('account.register.code_sent'));
    }
}
