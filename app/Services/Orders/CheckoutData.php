<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\CheckoutChoice;
use App\Enums\OrderChannel;
use App\Services\Sales\CheckoutMethods;
use Carbon\CarbonInterface;

/**
 * Validated checkout form (SF-44). `deliveryKey` / `paymentKey` are keys from {@see CheckoutMethods}; they may be empty
 * when the checkout has no order part (a pure reservation needs neither, MR-06).
 */
final readonly class CheckoutData
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $email,
        public string $deliveryKey,
        public ?string $address,
        public string $paymentKey,
        public ?string $comment,
        public bool $consent,
        public string $ip,
        public ?string $userAgent = null,
        /** AG-11: the «Мне исполнилось N лет» checkbox. */
        public bool $ageConfirmed = false,
        /** PM-24: the promo link of the `shop_pl` cookie (last click), checked by the service. */
        public ?int $promoLinkId = null,
        /** MR-04: where the `flexible` rows of the cart go. */
        public CheckoutChoice $choice = CheckoutChoice::Order,
        /** MR-06: the optional desired pickup date of the reservation part. */
        public ?CarbonInterface $desiredDate = null,
        /**
         * MR-02: the part of every cart row (cart item id => `order` / `reservation`) the customer saw on the checkout
         * page; null = not checked. A row that goes elsewhere on the server aborts the checkout.
         *
         * @var array<int, string>|null
         */
        public ?array $shownTargets = null,
        /** TM-14: where the request came from (decided by OrderChannelResolver). */
        public OrderChannel $channel = OrderChannel::Web,
    ) {}
}
