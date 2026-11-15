<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Enums\PhoneVerificationPurpose;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\Concerns\RequiresOpenShop;
use App\Livewire\Storefront\Concerns\SalesFormSupport;
use App\Livewire\Storefront\Concerns\SelectsVariant;
use App\Livewire\Storefront\Concerns\VerifiesPhone;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Access\ViewerContext;
use App\Services\Orders\InquiryData;
use App\Services\Orders\OrderService;
use App\Services\PhoneVerification\PhoneVerificationGate;
use App\Services\Sales\AvailabilityService;
use App\Support\QtyLimit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * «Оставить заявку» modal of the product page (SF-60, SF-71). A card's «Оставить заявку» opens the product page
 * with `?inquiry=1`, which passes `open`.
 */
class InquiryForm extends Component
{
    use RequiresOpenShop;
    use SalesFormSupport;
    use SelectsVariant;
    use VerifiesPhone;

    #[Locked]
    public int $productId;

    public bool $open = false;

    public int|string $qty = 1;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $comment = '';

    public bool $consent = false;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return $this->contactRules() + ['qty' => ['required', 'integer', 'min:1', 'max:'.QtyLimit::MAX]];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return $this->contactMessages();
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return $this->contactAttributes();
    }

    public function updatedPhone(string $value): void
    {
        $this->resetPhoneVerificationOnChange($value);
    }

    protected function pvPurpose(): PhoneVerificationPurpose
    {
        return PhoneVerificationPurpose::GuestCheckout;
    }

    protected function pvCustomer(): ?Customer
    {
        return app(ViewerContext::class)->customer();
    }

    protected function afterPhoneVerified(): void
    {
        $this->resetErrorBag('form');
        $this->normalizeEmailInput();
        $this->validate();
        $this->placeInquiry();
    }

    public function submit(): void
    {
        // The code step is open: Enter and the main button mean «Подтвердить».
        if ($this->pvActive) {
            $this->confirmPhoneCode();

            return;
        }

        $product = $this->product();
        $this->resetErrorBag('form');

        if ($product === null) {
            $this->addError('form', __('sales.errors.inquiry_unavailable'));

            return;
        }

        if ($this->unitOf($product) === null) {
            $this->addError('form', $this->variantError($product));

            return;
        }

        $this->normalizeEmailInput();
        $this->validate();

        if (! $this->passesGuard()) {
            return;
        }

        // PV-13 б: the call starts only after the rest of the form is valid.
        $gate = app(PhoneVerificationGate::class);
        $customer = $this->pvCustomer();

        if ($gate->accountBlocked($customer)) {
            $this->addError('form', __('phone_verification.errors.account_locked'));

            return;
        }

        if ($gate->guestNeedsVerification($customer, false, false, true, 0)
            && ! $gate->isPhoneVerified($this->phone, $this->pvPurpose(), $customer, PhoneVerificationGate::session())
            && ! $gate->wasSkipped($this->phone, $this->pvPurpose(), PhoneVerificationGate::session())
            && ! $this->beginPhoneVerification($this->phone)) {
            return;
        }

        $this->placeInquiry();
    }

    private function placeInquiry(): void
    {
        $product = $this->product();
        $orders = app(OrderService::class);

        if ($product === null) {
            $this->addError('form', __('sales.errors.inquiry_unavailable'));

            return;
        }

        try {
            $order = $orders->createInquiry($product, new InquiryData(
                qty: (int) $this->qty,
                name: $this->name,
                phone: $this->phone,
                email: $this->email !== '' ? $this->email : null,
                comment: $this->comment !== '' ? $this->comment : null,
                consent: $this->consent,
                ip: $this->clientIp(),
                userAgent: $this->clientUserAgent(),
                promoLinkId: $this->promoLinkId(),
                channel: $this->orderChannel(),
            ), $this->selectedVariantId());
        } catch (SalesException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->redirectRoute('order.thanks', ['uuid' => $order->uuid]);
    }

    public function render(AvailabilityService $availability): View
    {
        $product = $this->product();
        $unit = $product !== null ? ($this->unitOf($product) ?? $product) : null;

        return view('livewire.storefront.inquiry-form', [
            'product' => $product,
            'actions' => $unit !== null ? $availability->actionsFor($unit) : null,
            'consentLink' => $this->consentLink(),
            'mustSignIn' => $this->mustSignIn(),
            'accountLocked' => app(PhoneVerificationGate::class)->accountBlocked($this->pvCustomer()),
        ]);
    }

    private function product(): ?Product
    {
        return Product::query()->storefront()->find($this->productId);
    }
}
