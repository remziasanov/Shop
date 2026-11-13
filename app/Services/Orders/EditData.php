<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use App\Support\Money;

/**
 * The state the order editor form wants the order to be in (OE-02): positions, delivery, the manager's discount,
 * contacts and comments. Money is integer kopecks. Positions missing from `items` are removed. `notifyCustomer` is the
 * «Уведомить покупателя» checkbox (OE-09).
 */
final readonly class EditData
{
    /**
     * @param  list<EditItem>  $items
     */
    public function __construct(
        public array $items,
        public string $customerName,
        public string $customerPhone,
        public ?string $customerEmail,
        public ?string $deliveryMethod,
        public ?string $deliveryAddress,
        public int $deliveryPrice,
        public int $manualDiscount,
        public ?string $customerComment = null,
        public ?string $managerComment = null,
        public bool $notifyCustomer = false,
    ) {}

    /**
     * The data of the order as it is now: saving it changes nothing. Handy as the base of a form and of tests.
     */
    public static function fromOrder(Order $order, bool $notifyCustomer = false): self
    {
        return new self(
            array_values($order->items->map(fn ($item): EditItem => EditItem::fromItem($item))->all()),
            $order->customer_name,
            $order->customer_phone,
            $order->customer_email,
            $order->delivery_method,
            $order->delivery_address,
            Money::fromRubles($order->delivery_price),
            Money::fromRubles((string) $order->manual_discount),
            $order->customer_comment,
            $order->manager_comment,
            $notifyCustomer,
        );
    }

    /**
     * A copy with some fields replaced.
     *
     * @param  array<string, mixed>  $changes  constructor argument name => new value
     */
    public function with(array $changes): self
    {
        return new self(...array_merge([
            'items' => $this->items,
            'customerName' => $this->customerName,
            'customerPhone' => $this->customerPhone,
            'customerEmail' => $this->customerEmail,
            'deliveryMethod' => $this->deliveryMethod,
            'deliveryAddress' => $this->deliveryAddress,
            'deliveryPrice' => $this->deliveryPrice,
            'manualDiscount' => $this->manualDiscount,
            'customerComment' => $this->customerComment,
            'managerComment' => $this->managerComment,
            'notifyCustomer' => $this->notifyCustomer,
        ], $changes));
    }
}
