<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Enums\PhoneVerificationPurpose;
use App\Enums\VerificationPurpose;
use App\Exceptions\CustomerAuthException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\ChangeEmailRequest;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\CodeRequest;
use App\Http\Requests\Account\ProfileRequest;
use App\Models\Customer;
use App\Models\TelegramAccount;
use App\Services\Customers\CustomerAuthService;
use App\Services\PhoneVerification\PhoneChallenge;
use App\Services\PhoneVerification\PhoneVerificationGate;
use App\Settings\CustomerSettings;
use App\Support\PhoneNormalizer;
use App\Support\PiiMask;
use App\Support\TelegramMode;
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

    public function show(Request $request, CustomerSettings $settings, PhoneVerificationGate $gate): View
    {
        $customer = $this->customer($request);

        return $this->page('profile', __('account.profile.title'), [
            'customer' => $customer,
            'passwordMin' => $settings->password_min_length,
            'phonePrompt' => $gate->accountPrompt($customer),
            'telegramPhone' => $this->telegramPhone($request, $customer),
        ]);
    }

    public function update(ProfileRequest $request, CustomerAuthService $auth, PhoneVerificationGate $gate, PhoneChallenge $challenges): RedirectResponse
    {
        $customer = $this->customer($request);
        $name = (string) $request->validated('name');
        $phone = $request->normalizedPhone();

        // PV-07, PV-13 б: only a number that differs from the current one is verified; the form waits for the code.
        if ($customer->phone !== $phone && $gate->profileChangeEnabled()) {
            if (! $gate->isPhoneVerified($phone, PhoneVerificationPurpose::ProfileChange, $customer, $request->session())) {
                $challenges->parkProfileChange($request->session(), $customer, $name, $phone);

                return redirect()->route('customer.phone');
            }

            $auth->updateProfile($customer, $name, $phone, true);

            return redirect()->route('customer.profile')->with('status', __('phone_verification.screen.profile_saved_verified'));
        }

        // PV-13 е: a changed number resets the verification (also when the scenario is off).
        $auth->updateProfile($customer, $name, $phone);

        return redirect()->route('customer.profile')->with('status', __('account.profile.saved'));
    }

    /**
     * TG-09, PV-13 е: «Сохранить в профиль» — the phone Telegram verified goes to the profile and counts as verified.
     */
    public function saveTelegramPhone(Request $request, CustomerAuthService $auth): RedirectResponse
    {
        $customer = $this->customer($request);
        $phone = $this->telegramPhone($request, $customer);

        abort_if($phone === null, 404);

        $auth->updateProfile($customer, $customer->name, $phone, true);

        return redirect()->route('customer.profile')->with('status', __('phone_verification.screen.telegram_saved'));
    }

    /**
     * The verified phone of the Telegram account of this Mini App session, when it is a number the profile can hold and
     * differs from the profile phone or is not verified there yet; null otherwise.
     */
    private function telegramPhone(Request $request, Customer $customer): ?string
    {
        $accountId = TelegramMode::accountId($request->session());
        $account = $accountId === null ? null : TelegramAccount::query()->whereKey($accountId)->whereNotNull('phone_verified_at')->first(['id', 'phone']);
        $phone = $account !== null ? PhoneNormalizer::normalize($account->phone) : null;

        if ($phone === null || ($phone === $customer->phone && $customer->phone_verified_at !== null)) {
            return null;
        }

        return $phone;
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
    public function requestEmail(ChangeEmailRequest $request, CustomerAuthService $auth): RedirectResponse
    {
        try {
            $auth->requestEmailChange($this->customer($request), $request->email(), (string) $request->ip(), (string) $request->validated('email_current_password'), RegisterController::bindingToken($request));
        } catch (CustomerAuthException $e) {
            // The form has its own field name, so the error does not show under the change-password form.
            return $this->failure($request, $e->field === 'current_password' ? new CustomerAuthException($e->getMessage(), 'email_current_password') : $e);
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
            $auth->resendEmailChangeCode($this->customer($request), $email, (string) $request->ip(), RegisterController::bindingToken($request));
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
            $auth->confirmEmailChange($this->customer($request), $email, $request->code(), (string) $request->ip(), $request->session(), RegisterController::bindingToken($request));
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
