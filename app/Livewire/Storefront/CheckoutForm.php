<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Exceptions\CartChangedException;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\Concerns\RequiresOpenShop;
use App\Livewire\Storefront\Concerns\SalesFormSupport;
use App\Services\Cart\CartService;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\OrderService;
use App\Services\Sales\CheckoutMethods;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Checkout (SF-44…46, SF-48, SF-71). The order summary follows the chosen delivery method (SF-45); prices and
 * totals are recalculated by {@see OrderService::createFromCart()}, never taken from the form.
 */
class CheckoutForm extends Component
{
    use RequiresOpenShop;
    use SalesFormSupport;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $delivery = '';

    public string $address = '';

    public string $payment = '';

    public string $comment = '';

    public bool $consent = false;

    public function mount(CheckoutMethods $methods): void
    {
        $this->delivery = $methods->deliveries()[0]->key ?? '';
        $this->payment = $methods->payments()[0]->key ?? '';
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $methods = app(CheckoutMethods::class);
        $requiresAddress = $methods->delivery($this->delivery)->requiresAddress ?? false;

        return $this->contactRules() + [
            'delivery' => ['required', Rule::in(array_map(fn ($o) => $o->key, $methods->deliveries()))],
            'address' => [$requiresAddress ? 'required' : 'nullable', 'string', 'max:500'],
            'payment' => ['required', Rule::in(array_map(fn ($o) => $o->key, $methods->payments()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return $this->contactMessages() + [
            'delivery.required' => __('sales.errors.delivery_invalid'),
            'delivery.in' => __('sales.errors.delivery_invalid'),
            'address.required' => __('sales.errors.address_required'),
            'payment.required' => __('sales.errors.payment_invalid'),
            'payment.in' => __('sales.errors.payment_invalid'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return $this->contactAttributes() + [
            'delivery' => __('storefront_sales.fields.delivery'),
            'address' => __('storefront_sales.fields.address'),
            'payment' => __('storefront_sales.fields.payment'),
        ];
    }

    public function submit(CartService $carts, OrderService $orders): void
    {
        $cart = $carts->current(request());

        if ($cart === null) {
            $this->redirectRoute('cart');

            return;
        }

        $this->resetErrorBag('form');
        $this->validate();

        if (! $this->passesGuard()) {
            return;
        }

        try {
            $order = $orders->createFromCart($cart, new CheckoutData(
                name: $this->name,
                phone: $this->phone,
                email: $this->email !== '' ? $this->email : null,
                deliveryKey: $this->delivery,
                address: $this->address !== '' ? $this->address : null,
                paymentKey: $this->payment,
                comment: $this->comment !== '' ? $this->comment : null,
                consent: $this->consent,
                ip: $this->clientIp(),
                userAgent: $this->clientUserAgent(),
            ));
        } catch (CartChangedException $e) {
            // SF-46: the cart was already corrected; the customer re-checks it and confirms again.
            session()->flash('cart_changed', $e->reasons);
            $this->redirectRoute('cart');

            return;
        } catch (SalesException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->redirectRoute('order.thanks', ['uuid' => $order->uuid]);
    }

    public function render(CartService $carts, CheckoutMethods $methods): View
    {
        $cart = $carts->current(request());
        $summary = $cart !== null ? $carts->summary($cart) : null;
        $selected = $methods->delivery($this->delivery);
        $deliveryPrice = $selected !== null && $summary !== null ? $selected->priceFor($summary->itemsTotal) : 0;

        return view('livewire.storefront.checkout-form', [
            'summary' => $summary,
            'deliveries' => $summary !== null ? $summary->deliveryOptions : [],
            'payments' => $methods->payments(),
            'selectedDelivery' => $selected,
            'deliveryPrice' => $deliveryPrice,
            'total' => ($summary !== null ? $summary->itemsTotal : 0) + $deliveryPrice,
            'consentLink' => $this->consentLink(),
        ]);
    }
}
