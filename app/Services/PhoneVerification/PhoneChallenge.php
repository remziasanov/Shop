<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Enums\PhoneVerificationPurpose;
use App\Exceptions\WholesaleApplicationException;
use App\Models\Customer;
use App\Services\Customers\CustomerAuthService;
use App\Services\Customers\WholesaleApplicationService;
use Illuminate\Contracts\Session\Session;

/**
 * A cabinet action that waits for a verified phone (PV-07): the new phone of the profile, a wholesale application, or the
 * verification of the profile phone itself (registration scenario). The validated form is parked in the session while the
 * customer enters the code on `/account/phone`; the action runs only after the number is verified (PV-13 б: the call
 * starts after the rest of the form is valid), and runs without verification only when the provider failed and the owner
 * chose «пропускать» (PV-05).
 */
final class PhoneChallenge
{
    public const KEY = 'phone_verification.challenge';

    public function __construct(
        private readonly PhoneVerificationGate $gate,
        private readonly CustomerAuthService $auth,
        private readonly WholesaleApplicationService $wholesale,
    ) {}

    /**
     * @param  string  $phone  canonical +7XXXXXXXXXX
     */
    public function parkProfileChange(Session $session, Customer $customer, string $name, string $phone): void
    {
        $session->put(self::KEY, ['customer' => $customer->id, 'purpose' => PhoneVerificationPurpose::ProfileChange->value, 'phone' => $phone, 'name' => $name]);
    }

    /**
     * @param  array{company_name: string, inn: string, kpp: string|null, contact_name: string, phone: string, comment: string|null}  $data
     */
    public function parkWholesale(Session $session, Customer $customer, array $data): void
    {
        $session->put(self::KEY, ['customer' => $customer->id, 'purpose' => PhoneVerificationPurpose::Wholesale->value, 'phone' => $data['phone'], 'data' => $data]);
    }

    /**
     * What waits for the verification: the parked action, or (without one) the profile phone of the customer when the
     * registration scenario asks for it.
     *
     * @return array{purpose: PhoneVerificationPurpose, phone: string, parked: bool}|null
     */
    public function current(Session $session, Customer $customer): ?array
    {
        $parked = $this->parked($session, $customer);

        if ($parked !== null && is_string($parked['phone'] ?? null) && ($purpose = PhoneVerificationPurpose::tryFrom((string) ($parked['purpose'] ?? ''))) !== null) {
            return ['purpose' => $purpose, 'phone' => $parked['phone'], 'parked' => true];
        }

        if ($this->gate->accountPrompt($customer) && is_string($customer->phone) && $customer->phone !== '') {
            return ['purpose' => PhoneVerificationPurpose::Registration, 'phone' => $customer->phone, 'parked' => false];
        }

        return null;
    }

    public function clear(Session $session): void
    {
        $session->forget(self::KEY);
    }

    /**
     * Runs the parked action (or marks the profile phone verified).
     *
     * @param  bool  $verified  the number is verified; false = the provider failed and the owner chose to skip
     * @return array{route: string, status: string|null, error: string|null} where to go and what to tell the customer
     */
    public function complete(Session $session, Customer $customer, bool $verified, string $ip, ?string $userAgent): array
    {
        $parked = $this->parked($session, $customer);
        $purpose = $parked !== null ? PhoneVerificationPurpose::tryFrom((string) ($parked['purpose'] ?? '')) : null;
        $this->clear($session);

        if ($purpose === PhoneVerificationPurpose::ProfileChange && is_string($parked['name'] ?? null) && is_string($parked['phone'] ?? null)) {
            $this->auth->updateProfile($customer, $parked['name'], $parked['phone'], $verified);

            return ['route' => 'customer.profile', 'status' => __($verified ? 'phone_verification.screen.profile_saved_verified' : 'phone_verification.screen.profile_saved'), 'error' => null];
        }

        if ($purpose === PhoneVerificationPurpose::Wholesale && is_array($parked['data'] ?? null)) {
            try {
                /** @var array{company_name: string, inn: string, kpp: string|null, contact_name: string, phone: string, comment: string|null} $data */
                $data = $parked['data'];
                $this->wholesale->submit($customer, $data, $ip, $userAgent);
            } catch (WholesaleApplicationException $e) {
                return ['route' => 'customer.wholesale', 'status' => null, 'error' => $e->getMessage()];
            }

            return ['route' => 'customer.wholesale', 'status' => __('wholesale.form.sent'), 'error' => null];
        }

        // The registration scenario: the profile phone itself.
        if ($verified) {
            $this->auth->markPhoneVerified($customer);

            return ['route' => 'account', 'status' => __('phone_verification.screen.verified_done'), 'error' => null];
        }

        return ['route' => 'account', 'status' => __('phone_verification.screen.skipped'), 'error' => null];
    }

    /**
     * The parked action of THIS customer; another customer's leftovers are dropped.
     *
     * @return array<string, mixed>|null
     */
    private function parked(Session $session, Customer $customer): ?array
    {
        $parked = $session->get(self::KEY);

        if (! is_array($parked)) {
            return null;
        }

        if (($parked['customer'] ?? null) !== $customer->id) {
            $session->forget(self::KEY);

            return null;
        }

        return $parked;
    }
}
