<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Exceptions\SalesException;
use App\Livewire\Storefront\Concerns\SalesFormSupport;
use App\Models\Product;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReservationData;
use App\Services\Sales\AvailabilityService;
use App\Settings\SalesSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Throwable;

/**
 * «Зарезервировать» modal of the product page (SF-50, SF-51).
 */
class ReservationForm extends Component
{
    use SalesFormSupport;

    public int $productId;

    /** Opened on load (?reserve=1 from a product card). */
    public bool $open = false;

    public int|string $qty = 1;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $desiredDate = '';

    public string $comment = '';

    public bool $consent = false;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $max = $this->product() !== null ? app(AvailabilityService::class)->actionsFor($this->product())->maxReserveQty : null;

        return $this->contactRules() + [
            'qty' => ['required', 'integer', 'min:1', 'max:'.($max ?? 9999)],
            'desiredDate' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return $this->contactMessages() + [
            'qty.max' => __('sales.errors.reservation_qty', ['max' => ':max']),
            'desiredDate.date_format' => __('sales.errors.desired_date'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return $this->contactAttributes() + ['desiredDate' => __('storefront_sales.fields.desired_date')];
    }

    public function submit(OrderService $orders): void
    {
        $product = $this->product();
        $this->resetErrorBag('form');

        if ($product === null) {
            $this->addError('form', __('sales.errors.reservation_unavailable'));

            return;
        }

        $this->validate();

        if (! $this->passesGuard()) {
            return;
        }

        try {
            $desired = $this->desiredDate !== '' ? Carbon::createFromFormat('Y-m-d', $this->desiredDate) : null;
        } catch (Throwable) {
            $desired = null;
        }

        try {
            $order = $orders->createReservation($product, new ReservationData(
                qty: (int) $this->qty,
                name: $this->name,
                phone: $this->phone,
                email: $this->email !== '' ? $this->email : null,
                desiredDate: $desired ?: null,
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

    public function render(AvailabilityService $availability, SalesSettings $settings): View
    {
        $product = $this->product();
        $actions = $product !== null ? $availability->actionsFor($product) : null;

        return view('livewire.storefront.reservation-form', [
            'product' => $product,
            'actions' => $actions,
            'reservedUntil' => now()->addHours($settings->reservation_hours),
            'consentLink' => $this->consentLink(),
        ]);
    }

    private function product(): ?Product
    {
        return Product::query()->active()->find($this->productId);
    }
}
