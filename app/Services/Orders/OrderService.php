<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Exceptions\CartChangedException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Exceptions\SalesException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use App\Services\Sales\AvailabilityService;
use App\Services\Sales\CheckoutMethods;
use App\Services\Stock\StockService;
use App\Settings\SalesSettings;
use App\Support\Money;
use App\Support\PhoneNormalizer;
use App\Support\QtyLimit;
use Carbon\CarbonInterface;
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
    ) {}

    /**
     * Checkout (SF-46, PR-07): re-checks every item under lock, then snapshots them into an order.
     * When prices, availability or stock changed, nothing is created, the cart is brought up to date and
     * {@see CartChangedException} lists what changed.
     *
     * @throws CartChangedException
     * @throws SalesException
     */
    public function createFromCart(Cart $cart, CheckoutData $data): Order
    {
        $phone = $this->contact($data->name, $data->phone, $data->consent);

        $delivery = $this->methods->delivery($data->deliveryKey)
            ?? throw new SalesException(__('sales.errors.delivery_invalid'));
        $payment = $this->methods->payment($data->paymentKey)
            ?? throw new SalesException(__('sales.errors.payment_invalid'));
        $address = $this->blankToNull($data->address);

        if ($delivery->requiresAddress && $address === null) {
            throw new SalesException(__('sales.errors.address_required'));
        }

        // Retried on a deadlock (D-053): the callback only touches the DB and registers afterCommit callbacks,
        // which a rolled back attempt discards, so a repeat is safe.
        $result = DB::transaction(function () use ($cart, $data, $phone, $delivery, $payment, $address): Order|array {
            // First lock of the transaction: a second checkout of the same cart waits here and then sees it empty.
            if (Cart::query()->whereKey($cart->id)->lockForUpdate()->first() === null) {
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

            $reasons = [];
            $lines = [];

            foreach ($items as $item) {
                $product = $products->get($item->product_id);

                if ($product === null || ! $this->isSellable($product)) {
                    $reasons[] = __('sales.checkout.removed_unavailable', ['name' => $product->name ?? '']);
                    $item->delete();

                    continue;
                }

                $actions = $this->availability->actionsFor($product);

                if (! $actions->canAddToCart) {
                    $reasons[] = __($actions->outOfStock ? 'sales.checkout.removed_out_of_stock' : 'sales.checkout.removed_unavailable', ['name' => $product->name]);
                    $item->delete();

                    continue;
                }

                $qty = $item->qty;

                if ($actions->maxQty !== null && $qty > $actions->maxQty) {
                    $qty = $actions->maxQty;
                    $reasons[] = __('sales.checkout.qty_reduced', ['name' => $product->name, 'qty' => $qty]);
                    $item->qty = $qty;
                    $item->save();
                }

                $price = Money::fromRubles($product->final_price);
                $before = Money::fromRubles($item->price_at_add);

                if ($price !== $before) {
                    $reasons[] = __('sales.checkout.price_changed', [
                        'name' => $product->name,
                        'old' => Money::format($before),
                        'new' => Money::format($price),
                    ]);
                    $item->price_at_add = Money::toRubles($price);
                    $item->save();
                }

                $lines[] = ['product' => $product, 'qty' => $qty, 'price' => $price];
            }

            if ($reasons !== []) {
                // Commit the corrected cart, the exception is thrown after the transaction.
                return $reasons;
            }

            $itemsTotal = array_sum(array_map(fn (array $line): int => $line['price'] * $line['qty'], $lines));
            $min = $this->settings->min_order_amount;

            if ($min > 0 && $itemsTotal < $min) {
                throw new SalesException(__('sales.errors.min_order', [
                    'min' => Money::format($min),
                    'missing' => Money::format($min - $itemsTotal),
                ]));
            }

            $deliveryPrice = $delivery->priceFor($itemsTotal);

            $order = $this->createRecord(OrderType::Order, [
                'customer_name' => trim($data->name),
                'customer_phone' => $phone,
                'customer_email' => $this->blankToNull($data->email),
                'delivery_method' => $delivery->name,
                'delivery_address' => $address,
                'delivery_price' => Money::toRubles($deliveryPrice),
                'payment_method' => $payment->name,
                'items_total' => Money::toRubles($itemsTotal),
                'total' => Money::toRubles($itemsTotal + $deliveryPrice),
                'customer_comment' => $this->blankToNull($data->comment),
            ], $data->ip, $data->userAgent, $lines);

            $this->stock->deductForOrder($order);
            CartItem::query()->where('cart_id', $cart->id)->delete();

            return $this->finishCreate($order);
        }, self::DEADLOCK_ATTEMPTS);

        if (is_array($result)) {
            throw new CartChangedException($result);
        }

        return $result;
    }

    /**
     * Reservation of one product (SF-50, SF-51).
     *
     * @throws SalesException
     */
    public function createReservation(Product $product, ReservationData $data): Order
    {
        $phone = $this->contact($data->name, $data->phone, $data->consent);

        if ($data->qty < 1 || $data->qty > QtyLimit::MAX) {
            throw new SalesException(__('sales.errors.qty_invalid'));
        }

        return DB::transaction(function () use ($product, $data, $phone): Order {
            $locked = $this->lockSellable($product);
            $actions = $this->availability->actionsFor($locked);

            if (! $actions->canReserve) {
                throw new SalesException(__('sales.errors.reservation_unavailable'));
            }

            if ($actions->maxReserveQty !== null && $data->qty > $actions->maxReserveQty) {
                throw new SalesException(__('sales.errors.reservation_qty', ['max' => $actions->maxReserveQty]));
            }

            $reservedUntil = now()->addHours($this->settings->reservation_hours);

            if ($data->desiredDate !== null) {
                $desired = $data->desiredDate->copy()->startOfDay();

                if ($desired->lt(now()->startOfDay()) || $desired->gt($reservedUntil->copy()->startOfDay())) {
                    throw new SalesException(__('sales.errors.desired_date'));
                }
            }

            $price = Money::fromRubles($locked->final_price);

            $order = $this->createRecord(OrderType::Reservation, [
                'customer_name' => trim($data->name),
                'customer_phone' => $phone,
                'customer_email' => $this->blankToNull($data->email),
                'delivery_price' => '0.00',
                'items_total' => Money::toRubles($price * $data->qty),
                'total' => Money::toRubles($price * $data->qty),
                'customer_comment' => $this->blankToNull($data->comment),
                'reserved_until' => $reservedUntil,
                'desired_date' => $data->desiredDate?->copy()->startOfDay(),
            ], $data->ip, $data->userAgent, [['product' => $locked, 'qty' => $data->qty, 'price' => $price]]);

            $this->stock->holdForReservation($order);

            return $this->finishCreate($order);
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * Inquiry about a product (SF-60): the price is the current one or empty for «Цена по запросу»; no stock change.
     *
     * @throws SalesException
     */
    public function createInquiry(Product $product, InquiryData $data): Order
    {
        $phone = $this->contact($data->name, $data->phone, $data->consent);

        if ($data->qty < 1 || $data->qty > QtyLimit::MAX) {
            throw new SalesException(__('sales.errors.qty_invalid'));
        }

        return DB::transaction(function () use ($product, $data, $phone): Order {
            $locked = $this->lockSellable($product);

            if (! $this->availability->actionsFor($locked)->canInquire) {
                throw new SalesException(__('sales.errors.inquiry_unavailable'));
            }

            $price = $locked->price_on_request ? null : Money::fromRubles($locked->final_price);
            $total = $price === null ? null : Money::toRubles($price * $data->qty);

            $order = $this->createRecord(OrderType::Inquiry, [
                'customer_name' => trim($data->name),
                'customer_phone' => $phone,
                'customer_email' => $this->blankToNull($data->email),
                'delivery_price' => '0.00',
                'items_total' => $total,
                'total' => $total,
                'customer_comment' => $this->blankToNull($data->comment),
            ], $data->ip, $data->userAgent, [['product' => $locked, 'qty' => $data->qty, 'price' => $price]]);

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
        DB::transaction(function () use ($order, $user): void {
            /** @var Order|null $fresh */
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return;
            }

            $this->stock->settleForDeletion($fresh);
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

            $fresh->status = $to;
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
     * Locks a product row and checks that it is visible on the storefront.
     *
     * @throws SalesException
     */
    private function lockSellable(Product $product): Product
    {
        $locked = Product::query()->whereKey($product->id)->lockForUpdate()->first();

        if ($locked === null || ! $this->isSellable($locked)) {
            throw new SalesException(__('sales.errors.product_unavailable'));
        }

        return $locked;
    }

    private function isSellable(Product $product): bool
    {
        return ! $product->trashed() && $product->is_active && $this->tree->isVisible($product->category_id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{product: Product, qty: int, price: int|null}>  $lines
     */
    private function createRecord(OrderType $type, array $attributes, string $ip, ?string $userAgent, array $lines): Order
    {
        $order = new Order($attributes);
        $order->uuid = (string) Str::uuid();
        $order->number = $this->numbers->next($type);
        $order->type = $type;
        $order->status = OrderStatus::New;
        $order->consent_at = now();
        $order->consent_ip = $ip;
        $order->user_agent = $userAgent === null ? null : Str::limit($userAgent, 500, '');
        $order->save();

        foreach ($lines as $line) {
            $product = $line['product'];

            $order->items()->create([
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => Str::limit($product->name, 500, ''),
                'unit' => $product->unit,
                'price' => $line['price'] === null ? null : Money::toRubles($line['price']),
                'qty' => $line['qty'],
                'total' => $line['price'] === null ? null : Money::toRubles($line['price'] * $line['qty']),
            ]);
        }

        return $order->load('items');
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

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
