<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Enums\PhoneVerificationPurpose;
use App\Livewire\Storefront\Concerns\VerifiesPhone;
use App\Models\Customer;
use App\Services\Access\ViewerContext;
use App\Services\PhoneVerification\PhoneChallenge;
use App\Services\PhoneVerification\PhoneVerificationGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The verification page of the cabinet (`/account/phone`, PV-07, PV-13): the phone of the registration («Подтвердите
 * телефон»), a new profile phone or a wholesale application that waits for it. The call starts on the button, never on
 * page load (PV-13 б: the form behind it was validated already). A number of the customer's own Telegram is verified
 * without a call (TG-09).
 */
class PhoneVerificationPanel extends Component
{
    use VerifiesPhone;

    /** What the verification is for (`PhoneVerificationPurpose` value), decided on mount from the session. */
    #[Locked]
    public string $purposeValue = '';

    /** Canonical number to verify, decided on mount. */
    #[Locked]
    public string $targetPhone = '';

    #[Locked]
    public bool $parked = false;

    public function mount(PhoneChallenge $challenges): void
    {
        $customer = $this->pvCustomer();

        if ($customer === null) {
            $this->redirectRoute('customer.login');

            return;
        }

        $current = $challenges->current(PhoneVerificationGate::session(), $customer);

        if ($current === null) {
            $this->redirectRoute('customer.profile');

            return;
        }

        $this->purposeValue = $current['purpose']->value;
        $this->targetPhone = $current['phone'];
        $this->parked = $current['parked'];
    }

    protected function pvPurpose(): PhoneVerificationPurpose
    {
        return PhoneVerificationPurpose::tryFrom($this->purposeValue) ?? PhoneVerificationPurpose::Registration;
    }

    protected function pvCustomer(): ?Customer
    {
        return app(ViewerContext::class)->customer();
    }

    /**
     * «Позвонить мне» / «Подтвердить номер из Telegram».
     */
    public function startVerification(): void
    {
        $customer = $this->pvCustomer();

        if ($customer === null || $this->targetPhone === '') {
            return;
        }

        $gate = app(PhoneVerificationGate::class);

        // TG-09: the number of the customer's Telegram needs no call.
        if ($gate->isTelegramVerified($this->targetPhone, PhoneVerificationGate::session())) {
            $this->pvActive = false;
            $this->finish();

            return;
        }

        if ($this->beginPhoneVerification($this->targetPhone)) {
            $this->finish();
        }
    }

    /**
     * «Отменить» a parked action: the form is dropped, nothing was saved.
     */
    public function discard(PhoneChallenge $challenges): void
    {
        $challenges->clear(PhoneVerificationGate::session());
        $this->redirectRoute($this->purposeValue === PhoneVerificationPurpose::Wholesale->value ? 'customer.wholesale' : 'customer.profile');
    }

    protected function afterPhoneVerified(): void
    {
        $this->finish();
    }

    private function finish(): void
    {
        $customer = $this->pvCustomer();

        if ($customer === null) {
            return;
        }

        $done = app(PhoneChallenge::class)->complete(PhoneVerificationGate::session(), $customer, $this->pvPurpose(), $this->targetPhone, (string) request()->ip(), request()->userAgent());

        if ($done['status'] !== null) {
            session()->flash('status', $done['status']);
        }

        if ($done['error'] !== null) {
            session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['form' => $done['error']])));
        }

        $this->redirectRoute($done['route']);
    }

    public function render(): View
    {
        return view('livewire.storefront.phone-verification-panel', [
            'purpose' => $this->pvPurpose(),
            'phone' => $this->targetPhone,
        ]);
    }
}
