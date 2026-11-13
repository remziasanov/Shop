<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\AgeRestriction;
use App\Enums\CheckoutChoice;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Exceptions\CartChangedException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\SalesException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Access\ViewerContext;
use App\Services\Age\AgeGate;
use App\Services\Catalog\CategoryTree;
use App\Services\Customers\EmailNormalizer;
use App\Services\Pricing\Priceable;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\ResolvedPrice;
use App\Services\Promo\PromoLine;
use App\Services\Promo\PromoRedemptionService;
use App\Services\Promo\PromoResult;
use App\Services\Promo\PromoViewer;
use App\Services\Sales\AvailabilityService;
use App\Services\Sales\CheckoutMethods;
use App\Services\Stock\Stockable;
use App\Services\Stock\StockService;
use App\Services\Users\PermissionService;
use App\Services\Variants\OrderItemSnapshot;
use App\Settings\SalesSettings;
use App\Support\Money;
use App\Support\PhoneNormalizer;
use App\Support\QtyLimit;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates and moves requests: orders from the cart (SF-46), reservations (SF-50/51), inquiries (SF-60) and
 * status changes with their stock effects (Appendix B, OR-04/05). Everything runs in one DB transaction;
 * stock goes only through {@see StockService}. Events fire after commit (D-049).
 *
 * Failures are {@see SalesException}s with a Russian message; a changed cart is a {@see CartChangedException}.
 */
final class OrderService
{
    /** Attempts of a creating transaction when the database reports a deadlock (D-053). */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly StockService $stock,
        private readonly OrderNumberGenerator $numbers,
        private readonly StatusTransitions $transitions,
        private readonly AvailabilityService $availability,
        private readonly CheckoutMethods $methods,
        private readonly CategoryTree $tree,
        private readonly SalesSettings $settings,
        private readonly AgeGate $age,
        private readonly ViewerContext $viewer,
        private readonly OrderItemSnapshot $snapshots,
        private readonly PriceResolver $resolver,
        private readonly PromoRedemptionService $promos,
    ) {}

    /**
     * Checkout of the cart for callers that need one request (SF-46): the order when the cart has an order part,
     * otherwise the reservation. {@see checkoutFromCart()} returns everything that was created.
     *
     * @throws CartChangedException
     * @throws SalesException
     */
    public function createFromCart(Cart $cart, CheckoutData $data): Order
    {
        return $this->checkoutFromCart($cart, $data)->primary();
    }

    /**
     * Checkout (SF-46, PR-07, MR-05): re-checks every item under lock, then snapshots them into an order and/or a
     * reservation in ONE transaction with ONE lock pass (cart, products, variants by ascending id; 19.5). Every row is
     * re-resolved here (kind, availability, price): `order` rows and the `flexible` rows chosen as order make the order,
     * `reservation` rows and the `flexible` rows chosen as reservation make the reservation (all or nothing). A mixed
     * checkout links the two requests through `related_order_id` (on both rows). The minimum sum, the promo code,
     * delivery and payment belong to the order part only (MR-08).
     * When prices, availability or stock changed, nothing is created, the cart is brought up to date and
     * {@see CartChangedException} lists what changed (for a reservation that is short of stock the cart rows stay as
     * they are and the exception lists them in `shortages`, MR-07).
     *
     * @throws CartChangedException
     * @throws SalesException
     */
    public function checkoutFromCart(Cart $cart, CheckoutData $data): CheckoutResult
    {
        $this->viewer->assertNotPreview();
        // CA-01, CG-12: the server decides, whatever the form showed.
        $this->viewer->assertMayOrder();
        $phone = $this->contact($data->name, $data->phone, $data->consent);

        // A key that was typed must be valid whatever the cart holds; an empty one is only fine without an order part
        // (checked in the transaction, where the parts are known).
        if ($data->deliveryKey !== '' && $this->methods->delivery($data->deliveryKey) === null) {
            throw new SalesException(__('sales.errors.delivery_invalid'));
        }

        if ($data->paymentKey !== '' && $this->methods->payment($data->paymentKey) === null) {
            throw new SalesException(__('sales.errors.payment_invalid'));
        }

        // Retried on a deadlock (D-053): the callback only touches the DB and registers afterCommit callbacks,
        // which a rolled back attempt discards, so a repeat is safe.
        try {
            $result = DB::transaction(function () use ($cart, $data, $phone): CheckoutResult|CartChangedException {
                // First lock of the transaction: a second checkout of the same cart waits here and then sees it empty.
                $lockedCart = Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

                if ($lockedCart === null) {
                    throw new SalesException(__('sales.errors.cart_empty'));
                }

                $items = CartItem::query()->where('cart_id', $cart->id)->orderBy('id')->get();

                if ($items->isEmpty()) {
                    throw new SalesException(__('sales.errors.cart_empty'));
                }

                $products = Product::withTrashed()
                    ->whereIn('id', $items->pluck('product_id')->all())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // Second lock level (19.5): the variants of the cart rows, by ascending id.
                $variantIds = $items->pluck('variant_id')->filter()->all();
                $variants = $variantIds === []
                    ? collect()
                    : ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                // GP-13: the prices of the viewer's group for the locked rows, loaded at once and resolved inside the transaction.
                $group = $this->viewer->group();
                $book = $this->resolver->preload([...$products->values()->all(), ...$variants->values()->all()], $group);
                $reasons = [];
                $shortages = [];
                /** @var list<array{product: Product, variant: ProductVariant|null, qty: int, price: int, resolved: ResolvedPrice}> $orderLines */
                $orderLines = [];
                /** @var list<array{product: Product, variant: ProductVariant|null, qty: int, price: int, resolved: ResolvedPrice}> $reservationLines */
                $reservationLines = [];

                foreach ($items as $item) {
                    $product = $products->get($item->product_id);
                    $variant = $item->variant_id !== null ? $variants->get($item->variant_id) : null;

                    // VR-13: the card decides the sales mode, the variant decides availability, price and stock.
                    $broken = $product === null
                        || ! $this->isSellable($product)
                        || ($item->variant_id !== null && ($variant === null || ! $variant->is_active || (int) $variant->product_id !== (int) $product->id))
                        || ($item->variant_id === null && $product->has_variants);

                    if ($broken) {
                        $reasons[] = __('sales.checkout.removed_unavailable', ['name' => $product->name ?? '']);
                        $item->delete();

                        continue;
                    }

                    /** @var Stockable&Priceable $unit */
                    $unit = $variant !== null ? $variant->setRelation('product', $product) : $product;
                    $actions = $this->availability->actionsFor($unit);
                    // MR-02: the kind is resolved again, the cart row never says what it is.
                    $kind = $actions->cartKind($this->settings->reservation_via_cart);

                    if ($kind === null) {
                        $reasons[] = __($actions->outOfStock ? 'sales.checkout.removed_out_of_stock' : 'sales.checkout.removed_unavailable', ['name' => $unit->stockName()]);
                        $item->delete();

                        continue;
                    }

                    $target = $kind->target($data->choice);
                    $qty = $item->qty;

                    // MR-13, D-053: the limit of one row (the cart cuts it at add; a row from the outside is cut here).
                    if ($qty > QtyLimit::MAX) {
                        $qty = QtyLimit::MAX;
                        $reasons[] = __('sales.checkout.qty_limited', ['name' => $unit->stockName(), 'qty' => $qty]);
                        $item->qty = $qty;
                        $item->save();
                    }

                    if ($target === CheckoutChoice::Reservation) {
                        // MR-07: a reservation is all or nothing; the cart row is left as it is and the customer reduces it.
                        if ($actions->maxReserveQty !== null && $qty > $actions->maxReserveQty) {
                            $reasons[] = __('sales.checkout.reserve_short', ['name' => $unit->stockName(), 'available' => $actions->maxReserveQty, 'qty' => $qty]);
                            $shortages[] = $item->id;

                            continue;
                        }
                    } elseif ($actions->maxQty !== null && $qty > $actions->maxQty) {
                        $qty = $actions->maxQty;
                        $reasons[] = __('sales.checkout.qty_reduced', ['name' => $unit->stockName(), 'qty' => $qty]);
                        $item->qty = $qty;
                        $item->save();
                    }

                    // GP-11, GP-13: the unit price for the group and the quantity that is ordered.
                    $resolved = $this->resolver->resolve($unit, $group, $qty, $book);
                    $price = $resolved->unitPrice;
                    $before = Money::fromRubles($item->price_at_add);

                    if ($price !== $before) {
                        $reasons[] = __('sales.checkout.price_changed', [
                            'name' => $unit->stockName(),
                            'old' => Money::format($before),
                            'new' => Money::format($price),
                        ]);
                        $item->price_at_add = Money::toRubles($price);
                        $item->save();
                    }

                    $line = ['product' => $product, 'variant' => $variant, 'qty' => $qty, 'price' => $price, 'resolved' => $resolved];

                    if ($target === CheckoutChoice::Reservation) {
                        $reservationLines[] = $line;
                    } else {
                        $orderLines[] = $line;
                    }
                }

                if ($reasons !== []) {
                    // Commit the corrected cart, the exception is thrown after the transaction.
                    return new CartChangedException($reasons, $shortages);
                }

                // MR-13: the size of one reservation.
                if (count($reservationLines) > $this->settings->reservation_max_items) {
                    throw new SalesException(__('sales.errors.reservation_max_items', ['max' => $this->settings->reservation_max_items]));
                }

                $lines = [...$orderLines, ...$reservationLines];

                // AG-11: checked on the server against the locked products, whatever the cookie says.
                $ageLevel = $this->age->requiredForProducts(array_map(fn (array $line): Product => $line['product'], $lines));

                if ($ageLevel !== null && ! $data->ageConfirmed) {
                    throw new SalesException(__('age_gate.errors.confirm_required', ['age' => $ageLevel->minAge()]));
                }

                $email = $this->canonicalEmail($data->email);
                $order = null;

                if ($orderLines !== []) {
                    $order = $this->storeCartOrder($lockedCart, $orderLines, $data, $phone, $email, $ageLevel);

                    if (! $order instanceof Order) {
                        return $order;
                    }
                }

                $reservation = $reservationLines === [] ? null : $this->storeReservation(
                    $reservationLines,
                    $data->name,
                    $phone,
                    $email,
                    $data->desiredDate,
                    $data->comment,
                    $data->ip,
                    $data->userAgent,
                    $ageLevel,
                    $data->promoLinkId,
                    viaCart: true,
                );

                // MR-05: the two requests of one checkout point at each other.
                if ($order !== null && $reservation !== null) {
                    Order::query()->whereKey($order->id)->update(['related_order_id' => $reservation->id]);
                    Order::query()->whereKey($reservation->id)->update(['related_order_id' => $order->id]);
                    $order->related_order_id = $reservation->id;
                    $reservation->related_order_id = $order->id;
                    $order->syncOriginalAttribute('related_order_id');
                    $reservation->syncOriginalAttribute('related_order_id');
                }

                CartItem::query()->where('cart_id', $cart->id)->delete();

                return new CheckoutResult($order, $reservation);
            }, self::DEADLOCK_ATTEMPTS);
        } catch (InsufficientStockException $e) {
            // The transaction (both parts) was rolled back; the pre-checks make this a race with a change of stock.
            $shortage = CartItem::query()
                ->where('cart_id', $cart->id)
                ->where('product_id', $e->product->id)
                ->where('variant_key', $e->stockable->stockVariantId() ?? 0)
                ->value('id');

            throw new CartChangedException([$e->getMessage()], $shortage !== null ? [(int) $shortage] : []);
        }

        if ($result instanceof CartChangedException) {
            throw $result;
        }

        return $result;
    }

    /**
     * The order part of a checkout (SF-46, SF-43, SF-45, PM-11): minimum sum, promo code, delivery and payment, then the
     * stock deduction. Runs inside the checkout transaction after the products and variants are locked.
     *
     * @param  list<array{product: Product, variant: ProductVariant|null, qty: int, price: int, resolved: ResolvedPrice}>  $lines
     * @return Order|CartChangedException the exception when the promo code no longer applies (nothing was counted)
     *
     * @throws SalesException
     */
    private function storeCartOrder(Cart $lockedCart, array $lines, CheckoutData $data, string $phone, ?string $email, ?AgeRestriction $ageLevel): Order|CartChangedException
    {
        $delivery = $this->methods->delivery($data->deliveryKey)
            ?? throw new SalesException(__('sales.errors.delivery_invalid'));
        $payment = $this->methods->payment($data->paymentKey)
            ?? throw new SalesException(__('sales.errors.payment_invalid'));
        $address = $this->blankToNull($data->address);

        if ($delivery->requiresAddress && $address === null) {
            throw new SalesException(__('sales.errors.address_required'));
        }

        $itemsTotal = array_sum(array_map(fn (array $line): int => $line['price'] * $line['qty'], $lines));
        // CG-08: the group's minimum replaces the shop-wide one; checked on the server, against current prices.
        $min = $this->viewer->access()->minOrderAmount ?? $this->settings->min_order_amount;

        if ($min > 0 && $itemsTotal < $min) {
            throw new SalesException(__('sales.errors.min_order', [
                'min' => Money::format($min),
                'missing' => Money::format($min - $itemsTotal),
            ]));
        }

        // PM-11: the promo code is locked after the products and variants, validated again and counted atomically.
        $promoViewer = PromoViewer::fromContext($this->viewer, $email, $phone);
        $promo = null;

        if ($lockedCart->promo_code !== null && $lockedCart->promo_code !== '') {
            $promoLines = array_map(
                fn (array $line): PromoLine => PromoLine::make(self::promoKey($line['product'], $line['variant']), $line['product'], $line['variant']?->id, $line['qty'], $line['price'], PromoLine::oldPriceOf($line['resolved'])),
                $lines,
            );
            $promo = $this->promos->claim($lockedCart->promo_code, $promoLines, $promoViewer);

            if (! $promo->isApplied()) {
                // Back to the cart with the reason; the code leaves the cart, nothing was counted. The flag stops the
                // `shop_promo` cookie code from being re-applied (and refused again) — the customer must retype it.
                $lockedCart->promo_code = null;
                $lockedCart->promo_applied_manually = true;
                $lockedCart->save();

                return new CartChangedException([(string) $promo->message]);
            }
        }

        $promoDiscount = $promo === null ? 0 : $promo->discount;
        // PM-02: the free-delivery code zeroes the price over the «free from» rule of the method (SF-45).
        $deliveryPrice = $promo?->freeDelivery ? 0 : $delivery->priceFor($itemsTotal);

        $order = $this->createRecord(OrderType::Order, [
            'customer_name' => trim($data->name),
            'customer_phone' => $phone,
            'customer_email' => $email,
            'delivery_method' => $delivery->name,
            'delivery_address' => $address,
            'delivery_price' => Money::toRubles($deliveryPrice),
            'payment_method' => $payment->name,
            'items_total' => Money::toRubles($itemsTotal),
            // PM-18: items_total - promo_discount - manual_discount (0 at creation) + delivery_price.
            'total' => Money::toRubles($itemsTotal - $promoDiscount + $deliveryPrice),
            'customer_comment' => $this->blankToNull($data->comment),
            'age_confirmed_level' => $ageLevel,
        ], $data->ip, $data->userAgent, $lines, [
            'promo_code_id' => $promo?->code?->id,
            'promo_code' => $promo?->code?->code,
            'promo_discount' => Money::toRubles($promoDiscount),
            'promo_link_id' => $this->promos->validLinkId($data->promoLinkId),
        ], $promo);

        if ($promo !== null) {
            $this->promos->record($order, $promo, $promoViewer);
        }

        $this->stock->deductForOrder($order);

        return $this->finishCreate($order);
    }

    /**
     * The reservation record, its stock hold and its events: shared by the one-product form (SF-50) and the cart (MR-05,
     * MR-07). One `reserved_until` for every row (now + the configured hours, MR-06), one optional desired date; the hold
     * of all rows is all or nothing ({@see StockService::holdForReservation()} throws {@see InsufficientStockException}).
     * Runs inside a transaction with the products and variants of the rows locked.
     *
     * @param  list<array{product: Product, variant: ProductVariant|null, qty: int, price: int, resolved: ResolvedPrice}>  $lines
     *
     * @throws SalesException
     */
    private function storeReservation(array $lines, string $name, string $phone, ?string $email, ?CarbonInterface $desiredDate, ?string $comment, string $ip, ?string $userAgent, ?AgeRestriction $ageLevel, ?int $promoLinkId, bool $viaCart): Order
    {
        $reservedUntil = now()->addHours($this->settings->reservation_hours);

        if ($desiredDate !== null) {
            $desired = $desiredDate->copy()->startOfDay();

            if ($desired->lt(now()->startOfDay()) || $desired->gt($reservedUntil->copy()->startOfDay())) {
                throw new SalesException(__('sales.errors.desired_date'));
            }
        }

        $total = array_sum(array_map(fn (array $line): int => $line['price'] * $line['qty'], $lines));

        $order = $this->createRecord(OrderType::Reservation, [
            'customer_name' => trim($name),
            'customer_phone' => $phone,
            'customer_email' => $email,
            'delivery_price' => '0.00',
            'items_total' => Money::toRubles($total),
            'total' => Money::toRubles($total),
            'customer_comment' => $this->blankToNull($comment),
            'reserved_until' => $reservedUntil,
            'desired_date' => $desiredDate?->copy()->startOfDay(),
            'age_confirmed_level' => $ageLevel,
        ], $ip, $userAgent, $lines, [
            // PM-07, PM-24: no promo code, but the order is attributed to the promo link.
            'promo_link_id' => $this->promos->validLinkId($promoLinkId),
            'reserved_via_cart' => $viaCart,
        ]);

        $this->stock->holdForReservation($order);

        return $this->finishCreate($order);
    }

    /**
     * Reservation of one product, or of one of its variants (SF-50, SF-51, VR-14).
     *
     * @throws SalesException
     */
    public function createReservation(Product $product, ReservationData $data, ?int $variantId = null): Order
    {
        $this->viewer->assertNotPreview();
        // CA-01, CG-12: the server decides, whatever the form showed.
        $this->viewer->assertMayOrder();
        $phone = $this->contact($data->name, $data->phone, $data->consent);

        if ($data->qty < 1 || $data->qty > QtyLimit::MAX) {
            throw new SalesException(__('sales.errors.qty_invalid'));
        }

        return DB::transaction(function () use ($product, $data, $phone, $variantId): Order {
            [$locked, $variant] = $this->lockSellable($product, $variantId);
            $unit = $variant ?? $locked;
            $actions = $this->availability->actionsFor($unit);

            if (! $actions->canReserve) {
                throw new SalesException(__('sales.errors.reservation_unavailable'));
            }

            if ($actions->maxReserveQty !== null && $data->qty > $actions->maxReserveQty) {
                throw new SalesException(__('sales.errors.reservation_qty', ['max' => $actions->maxReserveQty]));
            }

            // AG-11: the same server-side check for a reservation.
            $ageLevel = $this->age->requiredForProducts([$locked]);

            if ($ageLevel !== null && ! $data->ageConfirmed) {
                throw new SalesException(__('age_gate.errors.confirm_required', ['age' => $ageLevel->minAge()]));
            }

            // GP-12: a reservation is priced for the viewer's group and the reserved quantity, too.
            $resolved = $this->resolver->resolve($unit, $this->viewer->group(), $data->qty);

            return $this->storeReservation(
                [['product' => $locked, 'variant' => $variant, 'qty' => $data->qty, 'price' => $resolved->unitPrice, 'resolved' => $resolved]],
                $data->name,
                $phone,
                $this->canonicalEmail($data->email),
                $data->desiredDate,
                $data->comment,
                $data->ip,
                $data->userAgent,
                $ageLevel,
                $data->promoLinkId,
                viaCart: false,
            );
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Inquiry about a product or one of its variants (SF-60, VR-14): the price is the current one or empty for
     * «Цена по запросу»; no stock change.
     *
     * @throws SalesException
     */
    public function createInquiry(Product $product, InquiryData $data, ?int $variantId = null): Order
    {
        $this->viewer->assertNotPreview();
        // CA-01, CG-12: the server decides, whatever the form showed.
        $this->viewer->assertMayOrder();
        $phone = $this->contact($data->name, $data->phone, $data->consent);

        if ($data->qty < 1 || $data->qty > QtyLimit::MAX) {
            throw new SalesException(__('sales.errors.qty_invalid'));
        }

        return DB::transaction(function () use ($product, $data, $phone, $variantId): Order {
            [$locked, $variant] = $this->lockSellable($product, $variantId);

            if (! $this->availability->actionsFor($variant ?? $locked)->canInquire) {
                throw new SalesException(__('sales.errors.inquiry_unavailable'));
            }

            // CG-06: a group that sees no prices gets an inquiry without a price.
            $resolved = $locked->price_on_request || ! $this->viewer->access()->showsPrices() ? null : $this->resolver->resolve($variant ?? $locked, $this->viewer->group(), $data->qty);
            $price = $resolved?->unitPrice;
            $total = $price === null ? null : Money::toRubles($price * $data->qty);

            $order = $this->createRecord(OrderType::Inquiry, [
                'customer_name' => trim($data->name),
                'customer_phone' => $phone,
                'customer_email' => $this->canonicalEmail($data->email),
                'delivery_price' => '0.00',
                'items_total' => $total,
                'total' => $total,
                'customer_comment' => $this->blankToNull($data->comment),
            ], $data->ip, $data->userAgent, [['product' => $locked, 'variant' => $variant, 'qty' => $data->qty, 'price' => $price, 'resolved' => $resolved]], [
                'promo_link_id' => $this->promos->validLinkId($data->promoLinkId),
            ]);

            return $this->finishCreate($order);
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Manual status change (OR-04) with the stock effects of Appendix B. For a reservation moving
     * `new` -> `reserved` a new term can be given.
     *
     * @throws InvalidOrderTransitionException
     * @throws SalesException
     */
    public function changeStatus(Order $order, OrderStatus $to, ?User $user = null, ?string $comment = null, ?CarbonInterface $newReservedUntil = null): Order
    {
        $this->transition($order, $to, $user, $comment, $newReservedUntil, bySystem: false, onlyIfOverdue: false);

        return $order;
    }

    /**
     * OE-12: «Создать заказ из заявки» - a new order from an inquiry, the inquiry is closed. The work is done by
     * {@see OrderEditor}, so that the stock, the prices and the checks are those of an edit.
     *
     * @throws SalesException
     * @throws AuthorizationException
     */
    public function createFromInquiry(Order $inquiry, int $expectedVersion, EditData $data, User $actor): Order
    {
        return app(OrderEditor::class)->createFromInquiry($inquiry, $expectedVersion, $data, $actor);
    }

    /**
     * Extend an active reservation by N hours counted from the later of now and the current term (OR-05).
     *
     * @throws SalesException
     */
    public function extendReservation(Order $order, int $hours, ?User $user = null): Order
    {
        if ($hours < 1) {
            throw new SalesException(__('sales.errors.extend_hours'));
        }

        DB::transaction(function () use ($order, $hours, $user): void {
            /** @var Order $fresh */
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->type !== OrderType::Reservation || ! in_array($fresh->status, [OrderStatus::New, OrderStatus::Reserved], true)) {
                throw new SalesException(__('sales.errors.extend_only_reservation'));
            }

            $base = $fresh->reserved_until !== null && $fresh->reserved_until->gt(now()) ? $fresh->reserved_until : now();
            $fresh->reserved_until = $base->copy()->addHours($hours);
            // A new term deserves a new "expires soon" notification (stage 6).
            $fresh->expiring_notified_at = null;
            $fresh->version = (int) $fresh->version + 1;
            $fresh->updated_by_user_id = $user?->id;
            $fresh->save();

            $this->history($fresh, $fresh->status, $fresh->status, $user, __('sales.history.extended', ['hours' => $hours]));
        });

        return $order->refresh();
    }

    /**
     * Admin deletion (OR-07, D-051): an active request that still holds stock gives it back (reservation: release,
     * order: return), a history row is written, then the request is soft deleted - all in one transaction.
     * Requests in a final status keep their stock effect. Deleting an already deleted request does nothing.
     */
    public function delete(Order $order, ?User $user = null): void
    {
        // RB-11: a staff member needs `orders.delete`; no user means a system action (console, cleanup).
        if ($user !== null && ! app(PermissionService::class)->allows($user, 'orders.delete')) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($order, $user): void {
            /** @var Order|null $fresh */
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            $this->stock->settleForDeletion($fresh);

            // A deleted active request gives its promo code back, like its stock (PM-12).
            if (! $fresh->status->isFinal()) {
                $this->promos->release($fresh);
            }

            $this->history($fresh, $fresh->status, $fresh->status, $user, __('sales.history.deleted'));
            $fresh->delete();
        });

        $order->refresh();
    }

    /**
     * Expire reservations whose term has passed (SF-53): status `expired`, reserved stock returned. Every
     * reservation is handled in its own transaction and re-checked under lock, so repeated runs are harmless.
     * Reservations are taken in batches; when `$deadline` (microtime) passes, the rest waits for the next run.
     * Returns the number of expired reservations.
     */
    public function expireReservations(?float $deadline = null): int
    {
        $expired = 0;
        $lastId = 0;
        $batch = 100;

        do {
            $ids = Order::query()->overdueReservations()->where('orders.id', '>', $lastId)->orderBy('orders.id')->limit($batch)->pluck('id');

            foreach ($ids as $id) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return $expired;
                }

                $lastId = (int) $id;

                try {
                    $order = Order::query()->find($id);

                    if ($order !== null && $this->transition($order, OrderStatus::Expired, null, __('sales.history.expired'), null, bySystem: true, onlyIfOverdue: true)) {
                        $expired++;
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        } while ($ids->count() === $batch);

        return $expired;
    }

    /**
     * @return bool whether the status was changed
     */
    private function transition(Order $order, OrderStatus $to, ?User $user, ?string $comment, ?CarbonInterface $newReservedUntil, bool $bySystem, bool $onlyIfOverdue): bool
    {
        $changed = DB::transaction(function () use ($order, $to, $user, $comment, $newReservedUntil, $bySystem, $onlyIfOverdue): bool {
            /** @var Order $fresh */
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $from = $fresh->status;

            if ($onlyIfOverdue && ($fresh->reserved_until === null || $fresh->reserved_until->gte(now()))) {
                return false;
            }

            if (! $this->transitions->canTransition($fresh->type, $from, $to, $bySystem)) {
                if ($onlyIfOverdue) {
                    return false;
                }

                throw new InvalidOrderTransitionException($fresh->type, $from, $to);
            }

            $note = $this->blankToNull($comment);

            if ($newReservedUntil !== null && $fresh->type === OrderType::Reservation && $to === OrderStatus::Reserved) {
                if ($newReservedUntil->lte(now())) {
                    throw new SalesException(__('sales.errors.reserved_until_past'));
                }

                $fresh->reserved_until = $newReservedUntil;
                $fresh->expiring_notified_at = null;
                $suffix = __('sales.history.new_reserved_until', ['date' => $newReservedUntil->format('d.m.Y H:i')]);
                $note = $note === null ? $suffix : $note.'. '.$suffix;
            }

            match (true) {
                $fresh->type === OrderType::Order && $to === OrderStatus::Cancelled => $this->stock->returnForOrder($fresh),
                $fresh->type === OrderType::Reservation && $to === OrderStatus::Completed => $this->stock->completeReservation($fresh),
                $fresh->type === OrderType::Reservation && in_array($to, [OrderStatus::Cancelled, OrderStatus::Expired], true) => $this->stock->releaseReservation($fresh),
                default => null,
            };

            // PM-12: the promo code goes back last (order, products, variants, promo code).
            if (in_array($to, [OrderStatus::Cancelled, OrderStatus::Expired], true)) {
                $this->promos->release($fresh);
            }

            $fresh->status = $to;
            // OE-08: a status change moves the version, so a form opened before it cannot overwrite it.
            $fresh->version = (int) $fresh->version + 1;
            $fresh->updated_by_user_id = $user?->id;
            $fresh->save();

            $this->history($fresh, $from, $to, $user, $note);

            DB::afterCommit(fn () => OrderStatusChanged::dispatch($fresh, $from, $to, $user, $bySystem));

            return true;
        });

        $order->refresh();

        return $changed;
    }

    /**
     * Validates the contact block and returns the normalized phone.
     *
     * @throws SalesException
     */
    private function contact(string $name, string $phone, bool $consent): string
    {
        if (! $consent) {
            throw new SalesException(__('sales.errors.consent_required'));
        }

        if (trim($name) === '') {
            throw new SalesException(__('sales.errors.name_required'));
        }

        return PhoneNormalizer::normalize($phone) ?? throw new SalesException(__('sales.errors.phone_invalid'));
    }

    /**
     * Locks a product row (and the variant row after it, VR-14) and checks that it is visible on the storefront. A
     * card with variants needs an active variant of its own; a card without variants takes none.
     *
     * @return array{0: Product, 1: ProductVariant|null}
     *
     * @throws SalesException
     */
    private function lockSellable(Product $product, ?int $variantId = null): array
    {
        $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();

        if ($locked === null || ! $this->isSellable($locked)) {
            throw new SalesException(__('sales.errors.product_unavailable'));
        }

        if (! $locked->has_variants) {
            return $variantId === null ? [$locked, null] : throw new SalesException(__('sales.errors.product_unavailable'));
        }

        if ($variantId === null) {
            throw new SalesException(__('variant_storefront.errors.required', ['name' => $locked->name]));
        }

        $variant = ProductVariant::query()
            ->whereKey($variantId)
            ->where('product_id', $locked->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first()
            ?? throw new SalesException(__('variant_storefront.errors.unavailable', ['name' => $locked->name]));

        return [$locked, $variant->setRelation('product', $locked)];
    }

    private function isSellable(Product $product): bool
    {
        // CG-14: also the section and category access of the viewer's customer group.
        return ! $product->trashed()
            && $product->is_active
            && $this->tree->isVisible($product->category_id)
            && $this->viewer->access()->canSeeProduct($product);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{product: Product, variant: ProductVariant|null, qty: int, price: int|null, resolved: ResolvedPrice|null}>  $lines
     * @param  array<string, mixed>  $promoFields  promo snapshot and attribution (PM-18, PM-24), not mass-assignable
     */
    private function createRecord(OrderType $type, array $attributes, string $ip, ?string $userAgent, array $lines, array $promoFields = [], ?PromoResult $promo = null): Order
    {
        $order = new Order($attributes);
        $order->uuid = (string) Str::uuid();
        $order->number = $this->numbers->next($type);
        $order->type = $type;
        $order->status = OrderStatus::New;
        $order->consent_at = now();
        $order->consent_ip = $ip;

        // CA-26, GP-12: a request under an account remembers the customer and a snapshot of their group at that moment.
        // A guest's request keeps no snapshot (it is priced for «Гость», GP-13).
        $customer = $this->viewer->customer();

        if ($customer !== null) {
            $group = $this->viewer->group();
            $order->customer_id = $customer->id;
            $order->customer_group_id = $group->exists ? $group->id : null;
            $order->customer_group_name = $group->name;
        }

        $order->user_agent = $userAgent === null ? null : Str::limit($userAgent, 500, '');
        $order->forceFill($promoFields)->save();

        foreach ($lines as $line) {
            // VR-12: the snapshot keeps the article, barcode and label of the variant even when it is deleted later.
            $order->items()->create($this->snapshots->for($line['product'], $line['variant']) + [
                'price' => $line['price'] === null ? null : Money::toRubles($line['price']),
                'qty' => $line['qty'],
                'total' => $line['price'] === null ? null : Money::toRubles($line['price'] * $line['qty']),
                // GP-12: where the unit price comes from, the tier and the retail price at that moment.
                'price_source' => $line['resolved']?->source,
                'tier_min_qty' => $line['resolved']?->tierMinQty,
                'retail_price' => $line['resolved'] === null ? null : Money::toRubles($line['resolved']->retailPrice),
                // PM-18: the share of the promo discount of this line.
                'promo_discount' => Money::toRubles($promo?->discountFor(self::promoKey($line['product'], $line['variant'])) ?? 0),
            ]);
        }

        return $order->load('items');
    }

    /**
     * The key of an order line in {@see PromoLine} and {@see PromoResult::$lineDiscounts}: card and variant id.
     */
    private static function promoKey(Product $product, ?ProductVariant $variant): string
    {
        return $product->id.':'.($variant === null ? 0 : $variant->id);
    }

    private function finishCreate(Order $order): Order
    {
        $this->history($order, null, OrderStatus::New, null, null);

        DB::afterCommit(fn () => OrderCreated::dispatch($order));

        return $order->refresh()->load('items');
    }

    private function history(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $user, ?string $comment): void
    {
        OrderStatusHistory::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $user?->id,
            'comment' => $comment,
            'created_at' => now(),
        ]);
    }

    /**
     * The contact e-mail of a request in canonical ASCII form (see EmailNormalizer), null when none was given.
     *
     * @throws SalesException the address has no canonical form
     */
    private function canonicalEmail(?string $value): ?string
    {
        $value = $this->blankToNull($value);

        if ($value === null) {
            return null;
        }

        return EmailNormalizer::normalize($value) ?? throw new SalesException(__('validation.canonical_email', ['attribute' => __('storefront_sales.fields.email')]));
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
