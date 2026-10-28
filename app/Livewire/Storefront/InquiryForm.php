<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Exceptions\SalesException;
use App\Livewire\Storefront\Concerns\RequiresOpenShop;
use App\Livewire\Storefront\Concerns\SalesFormSupport;
use App\Models\Product;
use App\Services\Orders\InquiryData;
use App\Services\Orders\OrderService;
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

    public function submit(OrderService $orders): void
    {
        $product = $this->product();
        $this->resetErrorBag('form');

        if ($product === null) {
            $this->addError('form', __('sales.errors.inquiry_unavailable'));

            return;
        }

        $this->validate();

        if (! $this->passesGuard()) {
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
            ));
        } catch (SalesException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->redirectRoute('order.thanks', ['uuid' => $order->uuid]);
    }

    public function render(AvailabilityService $availability): View
    {
        $product = $this->product();

        return view('livewire.storefront.inquiry-form', [
            'product' => $product,
            'actions' => $product !== null ? $availability->actionsFor($product) : null,
            'consentLink' => $this->consentLink(),
            'mustSignIn' => $this->mustSignIn(),
        ]);
    }

    private function product(): ?Product
    {
        return Product::query()->storefront()->find($this->productId);
    }
}
