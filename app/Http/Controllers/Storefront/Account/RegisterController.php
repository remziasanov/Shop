<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Exceptions\CustomerAuthException;
use App\Exceptions\FormGuardException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\RegisterRequest;
use App\Models\Order;
use App\Services\Customers\CustomerAuthService;
use App\Services\Sales\FormGuard;
use App\Settings\CustomerSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Registration (CA-02, CA-03, CA-05): the form, then the confirmation code page.
 */
class RegisterController extends Controller
{
    use RendersAccountPages;

    public const PENDING_EMAIL_KEY = 'customer_pending_email';

    /** Session key of the guest order whose e-mail prefills the registration form (CA-27). */
    public const THANKS_ORDER_KEY = 'customer_thanks_order';

    /** Session key of the token that binds a registration code to this browser (CA-05, see CustomerAuthService). */
    public const BINDING_KEY = 'customer_register_binding';

    /**
     * The binding token of this session; created on first use.
     */
    public static function bindingToken(Request $request): string
    {
        $token = $request->session()->get(self::BINDING_KEY);

        if (! is_string($token) || $token === '') {
            $token = Str::random(40);
            $request->session()->put(self::BINDING_KEY, $token);
        }

        return $token;
    }

    public function show(Request $request, FormGuard $guard, CustomerSettings $settings): View
    {
        abort_unless($settings->registration_enabled, 404);

        // CA-27: the «Спасибо» page remembers the guest order in the session; its e-mail prefills the form. The address
        // is never passed in the URL (NF-15).
        $email = null;
        $uuid = $request->session()->get(self::THANKS_ORDER_KEY);

        if (is_string($uuid) && Str::isUuid($uuid)) {
            $order = Order::query()->where('uuid', $uuid)->whereNull('customer_id')->first();
            $email = $order?->customer_email;
        }

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
            $auth->register($request->registrationData(self::bindingToken($request)));
        } catch (CustomerAuthException $e) {
            return $this->failure($request, $e);
        }

        $request->session()->put(self::PENDING_EMAIL_KEY, $request->email());

        return redirect()->route('customer.confirm')->with('status', __('account.register.code_sent'));
    }
}
