<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Enums\AgeRestriction;
use App\Enums\CheckoutChoice;
use App\Exceptions\CartChangedException;
use App\Exceptions\SalesException;
use App\Livewire\Storefront\Concerns\HandlesPromoCode;
use App\Livewire\Storefront\Concerns\RequiresOpenShop;
use App\Livewire\Storefront\Concerns\SalesFormSupport;
use App\Services\Access\ViewerContext;
use App\Services\Age\AgeGate;
use App\Services\Cart\CartService;
use App\Services\Cart\CartSummary;
use App\Services\Customers\CustomerAddressService;
use App\Services\Orders\CheckoutData;
use App\Services\Orders\OrderService;
use App\Services\Sales\CheckoutMethods;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Checkout (SF-44…46, SF-48, SF-71). The order summary follows the chosen delivery method (SF-45); prices and
 * totals are recalculated by {@see OrderService::createFromCart()}, never taken from the form.
 */
class CheckoutForm extends Component
{
    use HandlesPromoCode;
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

    /** AG-11: «Мне исполнилось N лет», shown when the cart holds restricted products. */
    public bool $ageConfirmed = false;

    /** CA-09: id of the chosen entry of the address book (a signed-in customer only). */
    public ?int $addressId = null;

    /** MR-04: where the `flexible` rows go: `order` or `reservation`. */
    public string $choice = 'order';

    /** MR-06: the optional desired pickup date of the reservation (Y-m-d). */
    public string $desiredDate = '';

    /**
     * MR-02: the part (`order` / `reservation`) every cart row had when the page was last rendered, by cart item id.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $shownTargets = [];

    public function mount(CheckoutMethods $methods, ViewerContext $viewer, CustomerAddressService $addresses): void
    {
        $this->delivery = $methods->deliveries()[0]->key ?? '';
        $this->payment = $methods->payments()[0]->key ?? '';

        // CA-09: a signed-in customer starts with the default address of the address book (name, phone and e-mail are
        // filled in by SalesFormSupport).
        $customer = $viewer->customer();
        $default = $customer !== null ? $addresses->listFor($customer)->first() : null;

        if ($default !== null) {
            $this->addressId = $default->id;
            $this->address = $default->address;
        }
    }

    /**
     * CA-09: picking an entry of the address book fills the address field (only the customer's own entries count).
     */
    public function updatedAddressId(mixed $value): void
    {
        $customer = app(ViewerContext::class)->customer();
        $entry = $customer !== null && is_numeric($value) ? app(CustomerAddressService::class)->find($customer, (int) $value) : null;

        if ($entry !== null) {
            $this->address = $entry->address;

            // The entry also carries its recipient and phone; empty values keep what the profile filled in.
            if ($entry->recipient_name !== '') {
                $this->name = $entry->recipient_name;
            }

            if ($entry->phone !== '') {
                $this->phone = $entry->phone;
            }
        } else {
            $this->addressId = null;
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $methods = app(CheckoutMethods::class);
        // MR-06: delivery and payment are asked (and required) only when something goes to the order.
        $summary = $this->summary();
        $needsOrder = $summary === null || $summary->hasOrder;
        $requiresAddress = $needsOrder && ($methods->delivery($this->delivery)->requiresAddress ?? false);

        return $this->contactRules() + $this->ageRules() + [
            'choice' => ['required', Rule::in(array_map(fn (CheckoutChoice $c): string => $c->value, CheckoutChoice::cases()))],
            'desiredDate' => ['nullable', 'date_format:Y-m-d'],
            'delivery' => [$needsOrder ? 'required' : 'nullable', Rule::in(array_map(fn ($o) => $o->key, $methods->deliveries()))],
            'address' => [$requiresAddress ? 'required' : 'nullable', 'string', 'max:500'],
            'payment' => [$needsOrder ? 'required' : 'nullable', Rule::in(array_map(fn ($o) => $o->key, $methods->payments()))],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return $this->contactMessages() + $this->ageMessages() + [
            'desiredDate.date_format' => __('sales.errors.desired_date'),
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
            'desiredDate' => __('storefront_sales.fields.desired_date'),
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
        $this->normalizeEmailInput();
        $this->validate();

        if (! $this->passesGuard()) {
            return;
        }

        try {
            $desired = $this->desiredDate !== '' ? Carbon::createFromFormat('Y-m-d', $this->desiredDate) : null;
        } catch (Throwable) {
            $desired = null;
        }

        $summary = $this->summary();
        $hasOrder = $summary === null || $summary->hasOrder;

        try {
            $result = $orders->checkoutFromCart($cart, new CheckoutData(
                name: $this->name,
                phone: $this->phone,
                email: $this->email !== '' ? $this->email : null,
                deliveryKey: $hasOrder ? $this->delivery : '',
                address: $hasOrder && $this->address !== '' ? $this->address : null,
                paymentKey: $hasOrder ? $this->payment : '',
                comment: $this->comment !== '' ? $this->comment : null,
                consent: $this->consent,
                ip: $this->clientIp(),
                userAgent: $this->clientUserAgent(),
                ageConfirmed: $this->ageConfirmed,
                promoLinkId: $this->promoLinkId(),
                choice: $this->summaryChoice(),
                desiredDate: $desired ?: null,
                shownTargets: $this->shownTargets === [] ? null : $this->shownTargets,
                channel: $this->orderChannel(),
            ));
        } catch (CartChangedException $e) {
            // SF-46: the cart was already corrected; the customer re-checks it and confirms again.
            session()->flash('cart_changed', $e->reasons);
            // MR-07: the rows a reservation was short of; the cart offers «Уменьшить до доступного» for them.
            session()->put('cart_shortages', $e->shortages);
            $this->redirectRoute('cart');

            return;
        } catch (SalesException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        // MR-11: the thanks page lists every request of this checkout (they are linked through `related_order_id`).
        $this->redirectRoute('order.thanks', ['uuid' => $result->primary()->uuid]);
    }

    public function render(CartService $carts, CheckoutMethods $methods, SalesSettings $sales): View
    {
        $cart = $carts->current(request());
        $customer = app(ViewerContext::class)->customer();
        $summary = $this->summary();
        $selected = $methods->delivery($this->delivery);
        $promo = $this->promoState($cart, $summary);
        $hasOrder = $summary !== null && $summary->hasOrder;
        $hasReservation = $summary !== null && $summary->hasReservation;
        $this->shownTargets = $summary === null ? [] : collect($summary->lines)
            ->filter(fn ($l): bool => $l->target !== null)
            ->mapWithKeys(fn ($l): array => [$l->item->id => $l->target->value])
            ->all();
        // PM-02: a free delivery code zeroes the price; PM-18: total = items - promo discount + delivery. MR-08: order part only.
        $deliveryPrice = $hasOrder && $selected !== null && ! $promo['freeDelivery'] ? $selected->priceFor($summary->itemsTotal) : 0;

        return view('livewire.storefront.checkout-form', [
            'summary' => $summary,
            'deliveries' => $summary !== null ? $summary->deliveryOptions : [],
            'payments' => $methods->payments(),
            'selectedDelivery' => $selected,
            'deliveryPrice' => $deliveryPrice,
            'promo' => $promo,
            'total' => ($summary !== null ? $summary->itemsTotal : 0) - $promo['discount'] + $deliveryPrice,
            'hasOrder' => $hasOrder,
            'hasReservation' => $hasReservation,
            'hasFlexible' => $summary !== null && $summary->hasFlexible,
            'orderLines' => $summary !== null ? array_values(array_filter($summary->lines, fn ($l): bool => $l->target !== CheckoutChoice::Reservation)) : [],
            'reservationLines' => $summary !== null ? array_values(array_filter($summary->lines, fn ($l): bool => $l->target === CheckoutChoice::Reservation)) : [],
            'reservedUntil' => now()->addHours($sales->reservation_hours),
            'consentLink' => $this->consentLink(),
            'ageLevel' => $this->ageLevel(),
            'savedAddresses' => $customer !== null ? app(CustomerAddressService::class)->listFor($customer) : [],
        ]);
    }

    protected function summaryChoice(): CheckoutChoice
    {
        return CheckoutChoice::tryFrom($this->choice) ?? CheckoutChoice::Order;
    }

    private ?CartSummary $loadedSummary = null;

    private bool $summaryLoaded = false;

    /**
     * The cart for the current choice (MR-04), built once per request; null without a cart.
     */
    private function summary(): ?CartSummary
    {
        if (! $this->summaryLoaded) {
            $carts = app(CartService::class);
            $cart = $carts->current(request());
            $this->loadedSummary = $cart !== null ? $carts->summary($cart, $this->summaryChoice()) : null;
            $this->summaryLoaded = true;
        }

        return $this->loadedSummary;
    }

    private ?AgeRestriction $loadedAgeLevel = null;

    private bool $ageLevelLoaded = false;

    /**
     * AG-11: the age to confirm for the products of the cart (null: gate off or nothing restricted).
     */
    protected function ageLevel(): ?AgeRestriction
    {
        if (! $this->ageLevelLoaded) {
            $cart = app(CartService::class)->current(request());
            $products = $cart !== null ? $cart->items()->with('product')->get()->pluck('product')->filter() : [];

            $this->loadedAgeLevel = app(AgeGate::class)->requiredForProducts($products);
            $this->ageLevelLoaded = true;
        }

        return $this->loadedAgeLevel;
    }
}
