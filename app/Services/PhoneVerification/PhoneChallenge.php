<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Enums\PhoneVerificationPurpose;
use App\Exceptions\WholesaleApplicationException;
use App\Models\Customer;
use App\Services\Customers\CustomerAuthService;
use App\Services\Customers\WholesaleApplicationService;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

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
     * Runs the parked action (or marks the profile phone verified) for the number that was actually verified.
     *
     * The verification of one number never applies anything of another: the purpose and the canonical phone of the
     * verification must equal those of the parked action (or, without one, of the fresh profile phone). Whether the
     * number is verified (or skipped) is re-checked here against the phone being applied. On a mismatch nothing is
     * applied, the stale parked action is dropped and a neutral message is returned (no enumeration).
     *
     * @param  PhoneVerificationPurpose  $purpose  what the customer verified
     * @param  string  $phone  the number that was verified (canonical or typed)
     * @return array{route: string, status: string|null, error: string|null} where to go and what to tell the customer
     */
    public function complete(Session $session, Customer $customer, PhoneVerificationPurpose $purpose, string $phone, string $ip, ?string $userAgent): array
    {
        $canonical = PhoneVerificationService::canonical($phone);
        $parked = $this->parked($session, $customer);
        $this->clear($session);

        $stale = ['route' => 'customer.profile', 'status' => null, 'error' => __('phone_verification.screen.stale')];

        if ($canonical === null) {
            return $stale;
        }

        $verified = $this->gate->isPhoneVerified($canonical, $purpose, $customer, $session);

        if (! $verified && ! $this->gate->wasSkipped($canonical, $purpose, $session)) {
            return $stale;
        }

        if ($parked !== null) {
            $parkedPurpose = PhoneVerificationPurpose::tryFrom((string) ($parked['purpose'] ?? ''));
            $parkedPhone = is_string($parked['phone'] ?? null) ? PhoneVerificationService::canonical($parked['phone']) : null;

            if ($parkedPurpose !== $purpose || $parkedPhone !== $canonical) {
                return $stale;
            }

            if ($purpose === PhoneVerificationPurpose::ProfileChange && is_string($parked['name'] ?? null)) {
                $this->auth->updateProfile($customer, $parked['name'], $canonical, $verified);

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

            return $stale;
        }

        // The registration scenario: the profile phone itself, read afresh and equal to the verified number.
        if ($purpose !== PhoneVerificationPurpose::Registration) {
            return $stale;
        }

        return DB::transaction(function () use ($customer, $canonical, $verified, $stale): array {
            $fresh = Customer::query()->whereKey($customer->id)->lockForUpdate()->first();

            if ($fresh === null || ! is_string($fresh->phone) || PhoneVerificationService::canonical($fresh->phone) !== $canonical) {
                return $stale;
            }

            if ($verified) {
                $this->auth->markPhoneVerified($fresh);

                return ['route' => 'account', 'status' => __('phone_verification.screen.verified_done'), 'error' => null];
            }

            return ['route' => 'account', 'status' => __('phone_verification.screen.skipped'), 'error' => null];
        });
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
