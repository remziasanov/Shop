<?php

declare(strict_types=1);

namespace App\Services\Customers;

use App\Enums\VerificationPurpose;
use App\Events\CustomerEmailVerified;
use App\Exceptions\CustomerAuthException;
use App\Exceptions\CustomerThrottledException;
use App\Mail\CustomerAccountExistsMail;
use App\Mail\CustomerEmailChangedMail;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerVerificationCode;
use App\Services\Cart\CartMerger;
use App\Services\Cart\CartService;
use App\Settings\CustomerSettings;
use App\Support\AtomicRateLimiter;
use App\Support\ClientIpKey;
use App\Support\PiiMask;
use App\Support\TelegramMode;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The customer account flows (CA-02…CA-08, CA-31, CA-32): registration with an e-mail code, sign-in by password or by
 * a one-time code, password reset by a code, profile and e-mail change, sign-out.
 *
 * Rules that hold everywhere:
 *  - Nothing here reveals whether an account exists for an address, except where the TZ says so (registering on a
 *    confirmed address, CA-05). Requests for a sign-in or reset code behave the same with and without an account.
 *  - Until the e-mail is confirmed there is no sign-in (CA-03). A blocked or anonymized customer cannot sign in.
 *  - Password sign-in: 5 attempts a minute per e-mail and IP; 5 failures lock that pair for 15 minutes (CA-06, CA-12).
 *  - Every event that must end the other sessions (password reset / change, e-mail change) increments
 *    `auth_version`; the current session gets the new value ({@see SESSION_VERSION_KEY}, CA-31).
 *  - Passwords and codes are never logged.
 *  - Every address is reduced to its canonical ASCII form ({@see EmailNormalizer}) before any lookup, and a row found
 *    by e-mail is used only when its stored address equals that form byte for byte: the database collation (accent-
 *    and case-insensitive on MySQL) must never decide whose account a code or a password belongs to.
 *  - A registration code is bound to the browser session that asked for it (CA-05): the pending name, phone and
 *    password hash travel with the code and are applied only when that very code is entered from that session.
 */
final class CustomerAuthService
{
    /** Session key of the `auth_version` the customer signed in with; compared by the guard middleware (CA-31, D-089). */
    public const SESSION_VERSION_KEY = 'customer_auth_version';

    /** Session key of the one-time permission to set a new password after a verified reset code (CA-07). */
    public const RESET_TICKET_KEY = 'customer_password_reset';

    /** CA-07: how long the new-password form stays open after the right code. */
    public const RESET_TICKET_MINUTES = 10;

    /** CA-02: registrations per hour and IP. */
    public const REGISTRATIONS_PER_IP_PER_HOUR = 5;

    /** CA-12: e-mail change requests per hour and customer (on top of the per-address and per-IP code limits). */
    public const EMAIL_CHANGES_PER_HOUR = 5;

    /** CA-06, CA-12: password attempts per minute and failures before the lock. */
    public const PASSWORD_ATTEMPTS_PER_MINUTE = 5;

    public const PASSWORD_FAILURES_BEFORE_LOCK = 5;

    /** Daily cap of current-password checks per customer (password and e-mail change share it). */
    public const CURRENT_PASSWORD_CHECKS_PER_DAY = 20;

    public const LOCK_SECONDS = 900;

    /** SEC-02: failed password sign-ins per client IP key (any address) before sign-in is refused for the window. */
    public const PASSWORD_FAILURES_PER_IP = 20;

    public const PASSWORD_FAILURES_PER_IP_WINDOW = 900;

    /** CA-02, TZ 17.2: the «remember me» cookie lives 30 days. */
    public const REMEMBER_MINUTES = 60 * 24 * 30;

    /** A bcrypt hash of a random string. */
    private const DUMMY_HASH = '$2y$12$1PJGy/iurL1q.UHvvcZ6depKwy3FnzASDmUsOFSrh1AqQZ38L2WS6';

    public function __construct(
        private readonly VerificationCodeService $codes,
        private readonly CustomerSettings $settings,
        private readonly CartMerger $cartMerger,
        private readonly OrderLinker $orderLinker,
    ) {}

    // ------------------------------------------------------------------ registration (CA-02, CA-03, CA-05)

    /**
     * Creates the customer (group by CG-11, e-mail unconfirmed) and sends the confirmation code. A registration on the
     * address of an unconfirmed account sends a new code (CA-05) and takes the account over, but only when that code is
     * entered: the name, phone and password of the registration travel with the code ({@see confirmRegistration()}),
     * so an earlier or a later registration of the same address can never confirm with somebody else's password.
     *
     * SEC-06: the answer must not tell whether the address has an account. For a confirmed account nothing is created or
     * changed, all code limits still run, and a «you already have an account» mail goes to the address instead of a
     * code; the method then returns null and the caller shows the same page as for a new address.
     *
     * @return Customer|null the account that got a code; null when the address already had a confirmed account
     *
     * @throws CustomerAuthException registration is off; resend too early
     * @throws CustomerThrottledException
     */
    public function register(RegistrationData $data): ?Customer
    {
        if (! $this->settings->registration_enabled) {
            throw new CustomerAuthException(__('account.errors.registration_disabled'));
        }

        $data = new RegistrationData($data->name, $this->canon($data->email), $data->phone, $data->password, $data->ip, $data->bindingToken);
        $ipKey = 'customer-register:ip:'.ClientIpKey::of($data->ip);

        if (AtomicRateLimiter::hit($ipKey, 3600) > self::REGISTRATIONS_PER_IP_PER_HOUR) {
            throw $this->throttled($ipKey);
        }

        try {
            $customer = $this->createOrRefreshUnverified($data);
        } catch (UniqueConstraintViolationException) {
            // A parallel registration took the address between the lookup and the insert: take the normal path again.
            try {
                $customer = $this->createOrRefreshUnverified($data);
            } catch (UniqueConstraintViolationException) {
                // The address is held by a row that is not spelled canonically (a legacy look-alike): it is not ours to
                // take, and the answer stays the same as for any other address (SEC-06).
                return null;
            }
        }

        if ($customer === null) {
            Mail::to($data->email)->queue(new CustomerAccountExistsMail);
        }

        return $customer;
    }

    /**
     * Sends a new registration code to an unconfirmed account («отправить код ещё раз»). Silent for any other address.
     */
    public function resendRegistrationCode(string $email, string $ip, ?string $bindingToken = null): void
    {
        $email = $this->canon($email);
        $customer = $this->findByEmail($email);
        $deliver = $customer !== null && ! $customer->hasVerifiedEmail() && $customer->anonymized_at === null;

        $this->codes->request(
            VerificationPurpose::Register,
            $email,
            $ip,
            $customer,
            $deliver ? $this->pendingPayload($email, $bindingToken) : null,
            deliver: $deliver,
            binding: $bindingToken,
        );
    }

    /**
     * Confirms the address with the code of the registration (CA-04); the account becomes usable and the event for the
     * guest-request linker fires (CA-14).
     *
     * The code carries the data of the registration that asked for it. It is accepted only from the session that holds
     * the matching binding token, and its name, phone and password hash are written to the account now, not earlier.
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     */
    public function confirmRegistration(string $email, string $code, string $ip, ?string $bindingToken = null): Customer
    {
        $email = $this->canon($email);
        $row = $this->codes->verify(VerificationPurpose::Register, $email, $code, $ip, $bindingToken);
        $customer = $this->findByEmail($email);

        if ($customer === null || $customer->anonymized_at !== null) {
            throw new CustomerAuthException(__('account.errors.code_invalid'), 'code');
        }

        $payload = is_array($row->payload) ? $row->payload : [];
        $binding = $payload['binding'] ?? null;

        if (is_string($binding) && ($bindingToken === null || ! hash_equals($binding, hash('sha256', $bindingToken)))) {
            // The code was asked for by another browser than the one entering it.
            throw new CustomerAuthException(__('account.errors.code_invalid'), 'code');
        }

        if (! $customer->hasVerifiedEmail()) {
            $fill = ['email_verified_at' => now()];

            if (is_string($payload['password'] ?? null) && is_string($payload['name'] ?? null) && is_string($payload['phone'] ?? null)) {
                $fill += [
                    'name' => $payload['name'],
                    'phone' => $payload['phone'],
                    // PV-13 е: the data of a re-registration (CA-05) replace the old ones, the phone is verified anew.
                    'phone_verified_at' => null,
                    'password' => $payload['password'],
                    'consent_at' => now(),
                    'consent_ip' => is_string($payload['consent_ip'] ?? null) ? $payload['consent_ip'] : $customer->consent_ip,
                ];
            }

            $customer->forceFill($fill)->save();
            CustomerEmailVerified::dispatch($customer);
        }

        return $customer;
    }

    // ------------------------------------------------------------------ sign-in (CA-06)

    /**
     * Checks e-mail and password with the limits of CA-06 / CA-12 and returns the customer who may sign in.
     *
     * @throws CustomerAuthException wrong data, blocked account, deletion pending
     * @throws CustomerThrottledException
     */
    public function authenticateByPassword(string $email, string $password, string $ip, ?string $bindingToken = null): Customer
    {
        $email = $this->canon($email);
        $ipKeyPart = ClientIpKey::of($ip);
        $pair = sha1($email.'|'.$ipKeyPart);
        $lockKey = 'customer-login:lock:'.$pair;
        $until = Cache::get($lockKey);

        if (is_int($until) && $until > now()->getTimestamp()) {
            throw new CustomerThrottledException($until - now()->getTimestamp());
        }

        // SEC-02: failed sign-ins of this client for ANY address; checked before the password hash is computed.
        $ipFailKey = 'customer-login:ip-fail:'.$ipKeyPart;

        // D-193: counted first (atomically) so a parallel burst cannot overshoot; the slot is given back on a successful
        // sign-in and when the pair limit below refuses the request before any password was checked.
        if (AtomicRateLimiter::hit($ipFailKey, self::PASSWORD_FAILURES_PER_IP_WINDOW) > self::PASSWORD_FAILURES_PER_IP) {
            throw $this->throttled($ipFailKey);
        }

        $attemptsKey = 'customer-login:attempts:'.$pair;

        if (AtomicRateLimiter::hit($attemptsKey, 60) > self::PASSWORD_ATTEMPTS_PER_MINUTE) {
            AtomicRateLimiter::decrement($ipFailKey, self::PASSWORD_FAILURES_PER_IP_WINDOW);

            throw $this->throttled($attemptsKey);
        }

        $customer = $this->findByEmail($email);
        // The hash is checked even for an unknown address, so both cases take the same time.
        $valid = Hash::check($password, $customer !== null ? $customer->password : self::DUMMY_HASH) && $customer !== null;

        // SEC-06: an account whose address is not confirmed cannot sign in with a password, and the answer is exactly the
        // one for wrong credentials; otherwise the sign-in would tell a freshly registered address from a registered one.
        // (A new unconfirmed account has an unusable hash anyway; this also covers older rows with a real hash.)
        if ($customer !== null && ! $customer->hasVerifiedEmail()) {
            $valid = false;
        }

        $failKey = 'customer-login:fail:'.$pair;

        if (! $valid) {
            $failures = AtomicRateLimiter::hit($failKey, self::LOCK_SECONDS);

            if ($failures >= self::PASSWORD_FAILURES_BEFORE_LOCK) {
                Cache::put($lockKey, now()->getTimestamp() + self::LOCK_SECONDS, self::LOCK_SECONDS);

                throw new CustomerThrottledException(self::LOCK_SECONDS);
            }

            throw new CustomerAuthException(__('account.errors.credentials'));
        }

        RateLimiter::clear($failKey);
        AtomicRateLimiter::decrement($ipFailKey, self::PASSWORD_FAILURES_PER_IP_WINDOW);

        if ($customer->is_blocked || $customer->anonymized_at !== null) {
            throw new CustomerAuthException(__('account.errors.blocked'));
        }

        if ($customer->deletion_requested_at !== null) {
            // CA-16: cancelling a deletion request needs the stronger proof of a code.
            throw new CustomerAuthException(__('account.errors.deletion_pending'));
        }

        return $customer;
    }

    /**
     * «Войти по коду», step 1: the answer is the same whether or not an account exists (CA-05).
     *
     * @throws CustomerAuthException resend too early
     * @throws CustomerThrottledException
     */
    public function requestLoginCode(string $email, string $ip, ?string $bindingToken = null): void
    {
        $email = $this->canon($email);
        $customer = $this->findByEmail($email);

        $this->codes->request(
            VerificationPurpose::Login,
            $email,
            $ip,
            $customer,
            deliver: $customer !== null && $customer->canSignIn(),
            binding: $bindingToken,
        );
    }

    /**
     * «Войти по коду», step 2. A sign-in with a code also cancels a pending deletion request (CA-16).
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     */
    public function authenticateByCode(string $email, string $code, string $ip, ?string $bindingToken = null): Customer
    {
        $email = $this->canon($email);
        $this->codes->verify(VerificationPurpose::Login, $email, $code, $ip, $bindingToken);

        $customer = $this->findByEmail($email);

        if ($customer === null || ! $customer->canSignIn()) {
            throw new CustomerAuthException(__('account.errors.blocked'), 'code');
        }

        if ($customer->deletion_requested_at !== null) {
            $customer->forceFill(['deletion_requested_at' => null])->save();
        }

        return $customer;
    }

    /**
     * Signs the customer in on the `customer` guard: a new session id (the `web` keys survive, CA-32), the version
     * for the CA-31 check, the time of the sign-in. "Remember me" lasts 30 days.
     */
    public function signIn(Customer $customer, Request $request, bool $remember = false): void
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('customer');
        $guard->setRememberDuration(self::REMEMBER_MINUTES);
        $guard->login($customer, $remember);
        $request->session()->put(self::SESSION_VERSION_KEY, $customer->auth_version);
        // TM-04: a password, code or reset sign-in is not a Telegram sign-in (the per-request link check must not apply).
        $request->session()->forget(TelegramMode::LOGIN_KEY);
        $customer->forceFill(['last_login_at' => now()])->save();
        $this->mergeGuestCart($customer, $request);
        $this->linkGuestOrders($customer);
    }

    /**
     * CA-32: the customer's sign-out leaves a staff session alone. The session is invalidated only when no staff
     * member is signed in in the same browser; the CSRF token is renewed in both cases.
     */
    public function signOut(Request $request): void
    {
        Auth::guard('customer')->logout();

        $session = $request->session();
        $session->forget([self::SESSION_VERSION_KEY, TelegramMode::LOGIN_KEY]);

        if (Auth::guard('web')->check()) {
            $session->regenerateToken();

            return;
        }

        // TM-04 (г): the Telegram mode is not a sign-in, so it survives the sign-out (the keys of the Telegram account).
        $telegram = TelegramMode::active($session) ? $session->only([
            TelegramMode::SESSION_KEY,
            TelegramMode::USER_KEY,
            TelegramMode::ACCOUNT_KEY,
            TelegramMode::CHAT_KEY,
            'channel',
        ]) : [];

        $session->invalidate();
        $session->put($telegram);
        $session->regenerateToken();
    }

    // ------------------------------------------------------------------ password reset (CA-07)

    /**
     * Step 1: the same answer with and without an account.
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     */
    public function requestPasswordReset(string $email, string $ip, ?string $bindingToken = null): void
    {
        $email = $this->canon($email);
        $customer = $this->findByEmail($email);

        $this->codes->request(
            VerificationPurpose::PasswordReset,
            $email,
            $ip,
            $customer,
            deliver: $customer !== null && $customer->canSignIn(),
            binding: $bindingToken,
        );
    }

    /**
     * Step 2: the right code opens the new-password form for 10 minutes, once (the ticket lives in the session).
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     */
    public function verifyPasswordResetCode(string $email, string $code, string $ip, Session $session, ?string $bindingToken = null): void
    {
        $email = $this->canon($email);
        $this->codes->verify(VerificationPurpose::PasswordReset, $email, $code, $ip, $bindingToken);

        $session->put(self::RESET_TICKET_KEY, [
            'email' => $email,
            'expires_at' => now()->addMinutes(self::RESET_TICKET_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * The e-mail the open reset ticket belongs to, or null (none, expired, used).
     */
    public function resetTicketEmail(Session $session): ?string
    {
        $ticket = $session->get(self::RESET_TICKET_KEY);

        if (! is_array($ticket) || ! is_string($ticket['email'] ?? null) || (int) ($ticket['expires_at'] ?? 0) < now()->getTimestamp()) {
            return null;
        }

        return $ticket['email'];
    }

    /**
     * Step 3: sets the new password. The ticket is single use; every other session ends (CA-07, CA-31).
     *
     * @throws CustomerAuthException no open ticket
     */
    public function resetPassword(Session $session, string $newPassword): Customer
    {
        $email = $this->resetTicketEmail($session);
        $session->forget(self::RESET_TICKET_KEY);

        $customer = $email !== null ? $this->findByEmail($email) : null;

        if ($customer === null || ! $customer->canSignIn()) {
            throw new CustomerAuthException(__('account.errors.reset_expired'), 'password');
        }

        // The reset was proven by a code, like a sign-in by code: it cancels a pending deletion request (CA-16).
        $customer->forceFill(['password' => $newPassword, 'deletion_requested_at' => null])->save();
        $customer->bumpAuthVersion();

        return $customer;
    }

    // ------------------------------------------------------------------ profile (CA-08)

    /**
     * PV-13 е: a changed phone number resets `phone_verified_at`; `$verified` (the number was verified by a code in this
     * session, or it is the verified number of the customer's Telegram) sets it again.
     *
     * @param  string  $phone  already normalized to +7XXXXXXXXXX
     */
    public function updateProfile(Customer $customer, string $name, string $phone, bool $verified = false): Customer
    {
        $changed = $customer->phone !== $phone;
        $fill = ['name' => trim($name), 'phone' => $phone];

        if ($verified) {
            $fill['phone_verified_at'] = now();
        } elseif ($changed) {
            $fill['phone_verified_at'] = null;
        }

        $customer->forceFill($fill)->save();

        return $customer;
    }

    /**
     * PV-07, PV-13: the profile phone was verified (a code, or the verified number of the customer's Telegram).
     */
    public function markPhoneVerified(Customer $customer): Customer
    {
        $customer->forceFill(['phone_verified_at' => now()])->save();

        return $customer;
    }

    /**
     * Changing the password needs the current one and ends the other sessions; this session continues (CA-08, CA-31).
     *
     * @throws CustomerAuthException the current password is wrong
     * @throws CustomerThrottledException
     */
    public function changePassword(Customer $customer, string $currentPassword, string $newPassword, Session $session): void
    {
        $this->assertCurrentPassword($customer, $currentPassword);

        $customer->forceFill(['password' => $newPassword])->save();
        $customer->bumpAuthVersion();
        $session->put(self::SESSION_VERSION_KEY, $customer->auth_version);
        // CA-08: the session continues. The password was just proved, so it becomes an ordinary sign-in; the Telegram link
        // keeps the old version and ends at the next /tg/session (TM-04 а).
        $session->forget(TelegramMode::LOGIN_KEY);
    }

    /**
     * Step 1 of the e-mail change: the current password is proved (SEC-05), then a code goes to the NEW address (CA-08).
     * Every customer has a password (`customers.password` is not nullable and registration is the only way to create
     * an account), so no separate path for password-less accounts exists.
     *
     * @throws CustomerAuthException the password is wrong, the same address, or an address of another confirmed account
     * @throws CustomerThrottledException
     */
    public function requestEmailChange(Customer $customer, string $newEmail, string $ip, string $currentPassword, ?string $bindingToken = null): void
    {
        $this->assertCurrentPassword($customer, $currentPassword);

        $this->issueEmailChangeCode($customer, $newEmail, $ip, $bindingToken);
    }

    /**
     * «Отправить код ещё раз» of a started e-mail change: the password was proved when the change started.
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     */
    public function resendEmailChangeCode(Customer $customer, string $newEmail, string $ip, ?string $bindingToken = null): void
    {
        $this->issueEmailChangeCode($customer, $newEmail, $ip, $bindingToken);
    }

    private function issueEmailChangeCode(Customer $customer, string $newEmail, string $ip, ?string $bindingToken): void
    {
        $newEmail = $this->canon($newEmail);

        if ($newEmail === $customer->email) {
            throw new CustomerAuthException(__('account.errors.email_same'), 'email');
        }

        // Too early for a new code: refused before the customer's own hourly limit is spent (a repeated click costs nothing).
        $this->codes->assertPauseOver(VerificationPurpose::ChangeEmail, $newEmail);

        // CA-05, CA-12: «the address is taken» is an answer that exists only behind the limits, otherwise a signed-in
        // customer could enumerate registered addresses. Every attempt counts per customer, per address and per IP.
        $customerKey = 'customer-email-change:'.$customer->id;

        if (AtomicRateLimiter::hit($customerKey, 3600) > self::EMAIL_CHANGES_PER_HOUR) {
            throw $this->throttled($customerKey);
        }

        $holder = Customer::query()->where('email', $newEmail)->whereNotNull('email_verified_at')->first();
        $taken = $holder !== null && hash_equals($holder->email, $newEmail);

        // For a taken address all code limits run and nothing is sent; only then the refusal is shown.
        $this->codes->request(VerificationPurpose::ChangeEmail, $newEmail, $ip, $customer, ['new_email' => $newEmail], deliver: ! $taken, binding: $bindingToken);

        if ($taken) {
            throw new CustomerAuthException(__('account.errors.email_taken'), 'email');
        }
    }

    /**
     * Step 2: the code from the new address changes the e-mail, ends the other sessions, keeps this one, and tells the
     * old address (CA-08). The guest requests of the new address are linked by the event (CA-14).
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     */
    public function confirmEmailChange(Customer $customer, string $newEmail, string $code, string $ip, Session $session, ?string $bindingToken = null): Customer
    {
        $newEmail = $this->canon($newEmail);
        $row = $this->codes->verify(VerificationPurpose::ChangeEmail, $newEmail, $code, $ip, $bindingToken);

        if ($row->customer_id !== $customer->id) {
            throw new CustomerAuthException(__('account.errors.code_invalid'), 'code');
        }

        $old = $customer->email;

        DB::transaction(function () use ($customer, $newEmail): void {
            // An unconfirmed account that only holds the address is dropped; it cannot own requests (CA-24).
            Customer::query()->where('email', $newEmail)->whereNull('email_verified_at')->where('id', '!=', $customer->id)->delete();

            // Any other row the database sees as the same address (even a non-canonical one) blocks the change.
            if (Customer::query()->where('email', $newEmail)->where('id', '!=', $customer->id)->exists()) {
                throw new CustomerAuthException(__('account.errors.email_taken'), 'email');
            }

            $customer->forceFill(['email' => $newEmail, 'email_verified_at' => now()])->save();
            $customer->bumpAuthVersion();
        });

        $session->put(self::SESSION_VERSION_KEY, $customer->auth_version);
        // CA-08: same as changePassword(): the session continues as an ordinary sign-in (TM-04 а).
        $session->forget(TelegramMode::LOGIN_KEY);

        Mail::to($old)->queue(new CustomerEmailChangedMail(PiiMask::email($newEmail)));
        CustomerEmailVerified::dispatch($customer);

        return $customer;
    }

    // ------------------------------------------------------------------ internals

    /**
     * @throws CustomerAuthException the address has no canonical ASCII form
     */
    private function canon(string $email): string
    {
        return EmailNormalizer::normalize($email) ?? throw new CustomerAuthException(__('account.errors.email_invalid'), 'email');
    }

    /**
     * The customer with exactly this canonical address. The database may return a look-alike row (accent- and
     * case-insensitive collation); such a row is not the customer.
     */
    private function findByEmail(string $email, bool $lock = false): ?Customer
    {
        $query = Customer::query()->where('email', $email);

        if ($lock) {
            $query->lockForUpdate();
        }

        $customer = $query->first();

        return $customer !== null && hash_equals($customer->email, $email) ? $customer : null;
    }

    /**
     * The payload of a resent registration code: the data of the latest registration made from this very session, or
     * only the binding when the session proved the account some other way (password sign-in).
     *
     * @return array<string, mixed>|null
     */
    private function pendingPayload(string $email, ?string $bindingToken): ?array
    {
        if ($bindingToken === null || $bindingToken === '') {
            return null;
        }

        $hash = hash('sha256', $bindingToken);
        $rows = CustomerVerificationCode::query()
            ->where('email', $email)
            ->where('purpose', VerificationPurpose::Register->value)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($rows as $row) {
            if (hash_equals($row->email, $email) && is_array($row->payload) && hash_equals((string) ($row->payload['binding'] ?? ''), $hash)) {
                return $row->payload;
            }
        }

        return ['binding' => $hash];
    }

    /**
     * CA-14: guest requests placed with the confirmed address after the confirmation join the history at the next
     * sign-in. A failure here never blocks the sign-in.
     */
    private function linkGuestOrders(Customer $customer): void
    {
        try {
            $this->orderLinker->link($customer);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * CA-15: the guest cart of this browser joins the cart of the customer. A failure here never blocks the sign-in.
     */
    private function mergeGuestCart(Customer $customer, Request $request): void
    {
        $token = $request->cookie(CartService::COOKIE);
        $tokens = is_string($token) ? [$token] : [];

        // TM-10: in the Telegram mode the guest cart is bound to the Telegram account; it joins the customer's cart too.
        $accountId = TelegramMode::accountId();
        $telegramToken = $accountId !== null
            ? Cart::query()->where('telegram_account_id', $accountId)->whereNull('customer_id')->value('token')
            : null;

        if (is_string($telegramToken) && ! in_array($telegramToken, $tokens, true)) {
            $tokens[] = $telegramToken;
        }

        foreach ($tokens as $guestToken) {
            try {
                $this->cartMerger->merge($customer, $guestToken);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // From now on the cart is the customer's own: the guest cookie must not outlive the sign-in.
        if (is_string($token)) {
            Cookie::queue(Cookie::forget(CartService::COOKIE));
        }
    }

    /**
     * Checks the current password of a signed-in customer (password change, e-mail change; SEC-05). One counter is
     * shared by both forms: 5 checks per minute and 20 per day. Every check is counted first and the attempt is given
     * back when the password is right, so only wrong guesses use the quota.
     *
     * @throws CustomerAuthException the password is wrong
     * @throws CustomerThrottledException
     */
    private function assertCurrentPassword(Customer $customer, string $currentPassword): void
    {
        $minuteKey = 'customer-current-password:minute:'.$customer->id;
        $dayKey = 'customer-current-password:day:'.$customer->id;

        // The minute limit goes first: a request it refuses must not use up the owner's daily checks.
        if (AtomicRateLimiter::hit($minuteKey, 60) > self::PASSWORD_ATTEMPTS_PER_MINUTE) {
            throw $this->throttled($minuteKey);
        }

        if (AtomicRateLimiter::hit($dayKey, 86400) > self::CURRENT_PASSWORD_CHECKS_PER_DAY) {
            AtomicRateLimiter::decrement($minuteKey, 60);

            throw $this->throttled($dayKey);
        }

        if (! Hash::check($currentPassword, $customer->password)) {
            throw new CustomerAuthException(__('account.errors.current_password'), 'current_password');
        }

        AtomicRateLimiter::decrement($dayKey, 86400);
        AtomicRateLimiter::decrement($minuteKey, 60);
    }

    private function throttled(string $key): CustomerThrottledException
    {
        return new CustomerThrottledException(max(1, RateLimiter::availableIn($key)));
    }

    /**
     * @return Customer|null null when the address belongs to a confirmed (or anonymized) account: nothing is changed,
     *                       but the code limits ran, so that case costs the requester the same as a new address (SEC-06)
     *
     * @throws CustomerAuthException
     * @throws CustomerThrottledException
     * @throws UniqueConstraintViolationException a parallel request took the address first
     */
    private function createOrRefreshUnverified(RegistrationData $data): ?Customer
    {
        return DB::transaction(function () use ($data): ?Customer {
            $customer = $this->findByEmail($data->email, lock: true);

            if ($customer !== null && ($customer->hasVerifiedEmail() || $customer->anonymized_at !== null)) {
                // Same work and same limits as for a new address: the password is hashed (twice, as below), the code limits are spent.
                Hash::make($data->password);
                Hash::make(Str::random(64));
                $this->codes->request(VerificationPurpose::Register, $data->email, $data->ip, null, null, deliver: false, binding: $data->bindingToken);

                return null;
            }

            if ($customer === null) {
                $customer = new Customer;
                $customer->forceFill([
                    'customer_group_id' => CustomerGroup::defaultForNew()->id,
                    'email' => $data->email,
                ]);
            }

            $hash = Hash::make($data->password);
            // A hash of a random string nobody knows (made with the configured cost, so the `hashed` cast accepts it).
            $unusable = Hash::make(Str::random(64));

            if (! $customer->exists) {
                // SEC-06: the chosen password is NOT stored yet (it travels in the code and is set on confirmation), so a
                // password sign-in cannot tell a freshly registered address from a registered one.
                $customer->forceFill([
                    'name' => trim($data->name),
                    'phone' => $data->phone,
                    'password' => $unusable,
                    'consent_at' => now(),
                    'consent_ip' => $data->ip,
                ])->save();
            }

            // CA-05: an existing unconfirmed account is NOT changed here. The data of this registration waits in the
            // code and replaces the stored data only when this code is confirmed, so a stranger who registered someone's
            // address cannot keep it, and cannot choose the password of an account the owner confirms either.
            $payload = [
                'name' => trim($data->name),
                'phone' => $data->phone,
                'password' => $hash,
                'consent_ip' => $data->ip,
            ];

            if ($data->bindingToken !== '') {
                $payload['binding'] = hash('sha256', $data->bindingToken);
            }

            $this->codes->request(VerificationPurpose::Register, $data->email, $data->ip, $customer, $payload, binding: $data->bindingToken);

            return $customer;
        });
    }
}
