<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Enums\OrderChangeKind;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PriceSource;
use App\Events\OrderItemsChanged;
use App\Exceptions\ConcurrentEditException;
use App\Exceptions\SalesException;
use App\Jobs\SendMailNotification;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Models\User;
use App\Services\Customers\EmailNormalizer;
use App\Services\Pricing\PriceBook;
use App\Services\Pricing\PriceResolver;
use App\Services\Pricing\ResolvedPrice;
use App\Services\Promo\PromoLine;
use App\Services\Promo\PromoService;
use App\Services\Stock\StockChange;
use App\Services\Stock\StockEffect;
use App\Services\Stock\StockService;
use App\Services\Users\PermissionService;
use App\Services\Variants\OrderItemSnapshot;
use App\Settings\NotificationSettings;
use App\Support\Money;
use App\Support\PhoneNormalizer;
use App\Support\QtyLimit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Editing the contents of an order before it is handed over (section 24, OE-01…OE-15). One save is one transaction:
 * lock the order, check the version (OE-08), the status (OE-01) and the permissions (OE-11), diff the form against the
 * stored order, move the stock through {@see StockService::applyItemChanges()} (OE-04, OE-05), write items, fields and
 * totals (kopecks, OE-06), write the change log with one batch id (OE-07) and move the version with a conditional UPDATE.
 * Everything the form says is re-checked here; nothing is trusted from the client. The customer is told after commit.
 *
 * {@see preview()} builds the same plan without writing anything (OE-10); {@see createFromInquiry()} turns an inquiry
 * into an order through the same pipeline (OE-12).
 */
final class OrderEditor
{
    /** Attempts of the transaction when the database reports a deadlock. */
    private const DEADLOCK_ATTEMPTS = 3;

    public function __construct(
        private readonly StockService $stock,
        private readonly PriceResolver $resolver,
        private readonly PromoService $promos,
        private readonly OrderItemSnapshot $snapshots,
        private readonly PermissionService $permissions,
        private readonly OrderNumberGenerator $numbers,
        private readonly NotificationSettings $notifications,
    ) {}

    /**
     * OE-01: order `new` / `confirmed` / `ready`, reservation `new` / `reserved`. Inquiries, shipped, completed,
     * cancelled and expired requests are not editable.
     */
    public static function isEditable(Order $order): bool
    {
        return match ($order->type) {
            OrderType::Order => in_array($order->status, [OrderStatus::New, OrderStatus::Confirmed, OrderStatus::Ready], true),
            OrderType::Reservation => in_array($order->status, [OrderStatus::New, OrderStatus::Reserved], true),
            OrderType::Inquiry => false,
        };
    }

    /**
     * Why the order cannot be edited (the hint of the disabled button), null when it can.
     */
    public static function blockReason(Order $order): ?string
    {
        if (self::isEditable($order)) {
            return null;
        }

        return $order->type === OrderType::Inquiry
            ? __('order_edit.errors.not_editable_inquiry')
            : __('order_edit.errors.not_editable_status', ['status' => $order->status->label($order->type)]);
    }

    /**
     * The staff member may open the editor: `orders.update` and `orders.edit_items` (OE-11).
     */
    public function mayEdit(User $user): bool
    {
        return $this->permissions->allowsAll($user, ['orders.update', 'orders.edit_items']);
    }

    public function mayEditPrices(User $user): bool
    {
        return $this->permissions->allows($user, 'orders.edit_prices');
    }

    /**
     * The plan of a save without writing anything (OE-10): diff, totals, stock effect per line, warnings. Checks the
     * same rules as {@see apply()}, except the version.
     *
     * @throws SalesException
     * @throws AuthorizationException
     */
    public function preview(int $orderId, EditData $data, User $actor): EditPlan
    {
        $order = Order::query()->whereKey($orderId)->first() ?? throw new SalesException(__('order_edit.errors.not_found'));

        $this->assertMayEdit($order, $actor);

        $plan = $this->build($order, $data, $actor, false);
        $plan->effects = $this->stock->previewItemChanges($order, $plan->stockChanges);
        $this->finish($order, $plan);

        return $plan;
    }

    /**
     * The plan of «Создать заказ из заявки» without writing anything (OE-10, OE-12): the new order is built in memory.
     *
     * @throws SalesException
     * @throws AuthorizationException
     */
    public function previewNewOrder(Order $inquiry, EditData $data, User $actor): EditPlan
    {
        if (! $this->permissions->allows($actor, 'orders.create_from_inquiry')) {
            throw new AuthorizationException;
        }

        $order = new Order;
        $order->type = OrderType::Order;
        $order->status = OrderStatus::New;
        $order->customer_group_id = $inquiry->customer_group_id;
        $order->stock_applied = $this->stock->trackingEnabled();

        $plan = $this->build($order, $data, $actor, true);
        $plan->effects = $this->stock->previewItemChanges($order, $plan->stockChanges);
        $this->finish($order, $plan);

        return $plan;
    }

    /**
     * The price list price (kopecks) of a product or variant for the group stored in the order and the quantity (OE-03):
     * the default of an added position and the result of «Пересчитать по прайсу». Null when the unit does not exist.
     */
    public function listPrice(Order $order, int $productId, ?int $variantId, int $qty): ?int
    {
        $product = Product::withTrashed()->find($productId);

        if ($product === null) {
            return null;
        }

        $variant = $variantId === null ? null : ProductVariant::withTrashed()->where('product_id', $productId)->find($variantId);

        if ($variantId !== null && $variant === null) {
            return null;
        }

        $variant?->setRelation('product', $product);
        $group = ($order->customer_group_id === null ? null : CustomerGroup::query()->find($order->customer_group_id)) ?? CustomerGroup::guest();

        return $this->resolver->resolve($variant ?? $product, $group, max(1, $qty))->unitPrice;
    }

    /**
     * Name, article and unit of a product or variant for a new row of the form.
     *
     * @return array{name: string, sku: string, unit: string}|null
     */
    public function describeUnit(int $productId, ?int $variantId): ?array
    {
        $product = Product::withTrashed()->find($productId);
        $variant = $variantId === null ? null : ProductVariant::withTrashed()->where('product_id', $productId)->find($variantId);

        if ($product === null || ($variantId !== null && $variant === null)) {
            return null;
        }

        $snapshot = $this->snapshots->for($product, $variant);

        return [
            'name' => $snapshot['variant_label'] !== null ? $snapshot['name'].' ('.$snapshot['variant_label'].')' : $snapshot['name'],
            'sku' => $snapshot['sku'],
            'unit' => $snapshot['unit'],
        ];
    }

    /**
     * Saves the form (24.5).
     *
     * @throws ConcurrentEditException the order changed after the form was opened
     * @throws SalesException a rule was violated, or the stock is short (the whole save is rolled back)
     * @throws AuthorizationException
     */
    public function apply(int $orderId, int $expectedVersion, EditData $data, User $actor): EditResult
    {
        return DB::transaction(function () use ($orderId, $expectedVersion, $data, $actor): EditResult {
            /** @var Order|null $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null) {
                throw new SalesException(__('order_edit.errors.not_found'));
            }

            $this->assertVersion($order, $expectedVersion);
            $this->assertMayEdit($order, $actor);

            $plan = $this->build($order, $data, $actor, false);

            if (! $plan->hasChanges()) {
                return new EditResult($order, false, null, $plan->oldTotal, $plan->oldTotal);
            }

            $plan->effects = $this->stock->applyItemChanges($order, $plan->stockChanges);
            $this->finish($order, $plan);

            // Last lock of the order, products, variants, promo code sequence (19.5, 22.6).
            if ($order->promo_code_id !== null) {
                PromoCode::query()->whereKey($order->promo_code_id)->lockForUpdate()->first();
            }

            $this->persist($order, $plan);

            $batch = (string) Str::uuid();
            $this->writeLog($order, $plan, $batch, $actor);
            $this->bumpVersion($order, $expectedVersion, $actor);

            $oldTotal = $plan->oldTotal;
            $newTotal = $plan->newTotal;
            DB::afterCommit(function () use ($order, $oldTotal, $newTotal, $data, $batch, $actor): void {
                app(NewRequestsCounter::class)->forget();
                OrderItemsChanged::dispatch($order, $oldTotal, $newTotal, $data->notifyCustomer, $batch, $actor);
            });

            return new EditResult($order->refresh(), true, $batch, $oldTotal, $newTotal);
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * The «Комментарий менеджера» action of the order card (OE-07, OE-08): one transaction updates the comment, moves
     * the version and writes one `order_changes` row. An unchanged comment writes nothing.
     *
     * @throws SalesException the order is gone
     */
    public function saveManagerComment(int $orderId, ?string $comment, User $actor): bool
    {
        return DB::transaction(function () use ($orderId, $comment, $actor): bool {
            /** @var Order|null $order */
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null) {
                throw new SalesException(__('order_edit.errors.not_found'));
            }

            $old = $this->blankToNull($order->manager_comment);
            $new = $this->blankToNull($comment);

            if ($old === $new) {
                return false;
            }

            $order->manager_comment = $new;
            $order->saveQuietly();

            OrderChange::query()->create([
                'order_id' => $order->id,
                'batch_uuid' => (string) Str::uuid(),
                'kind' => OrderChangeKind::Field,
                'field' => 'manager_comment',
                'order_item_id' => null,
                'item_label' => null,
                'old_value' => $old,
                'new_value' => $new,
                'user_id' => $actor->id,
                'created_at' => now(),
            ]);
            $this->bumpVersion($order, (int) $order->version, $actor);

            return true;
        });
    }

    /**
     * OE-12: an inquiry becomes an order. Creates a request of the `order` type through the same pipeline as an edit
     * (stock by OE-04, prices from the price list for the group of the inquiry, source_order_id = the inquiry, a number
     * from the common counter), closes the inquiry with the comment «Создан заказ Z-…» and, per the setting, mails the
     * customer. The form of the inquiry is checked against `$expectedVersion` like any edit.
     *
     * @throws ConcurrentEditException
     * @throws SalesException
     * @throws AuthorizationException
     */
    public function createFromInquiry(Order $inquiry, int $expectedVersion, EditData $data, User $actor): Order
    {
        return DB::transaction(function () use ($inquiry, $expectedVersion, $data, $actor): Order {
            /** @var Order|null $locked */
            $locked = Order::query()->whereKey($inquiry->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new SalesException(__('order_edit.errors.not_found'));
            }

            if (! $this->permissions->allows($actor, 'orders.create_from_inquiry')) {
                throw new AuthorizationException;
            }

            $this->assertVersion($locked, $expectedVersion);

            if ($locked->type !== OrderType::Inquiry || ! in_array($locked->status, [OrderStatus::New, OrderStatus::InProgress], true)) {
                throw new SalesException(__('order_edit.errors.inquiry_not_open'));
            }

            if (Order::query()->where('source_order_id', $locked->id)->exists()) {
                throw new SalesException(__('order_edit.errors.inquiry_converted'));
            }

            $order = new Order;
            $order->uuid = (string) Str::uuid();
            // The number is allocated after products and variants are locked (checkout lock order, D-053).
            $order->number = 'tmp-'.Str::random(20);
            $order->type = OrderType::Order;
            $order->status = OrderStatus::New;
            $order->customer_name = '';
            $order->customer_phone = '';
            $order->delivery_price = '0.00';
            $order->consent_at = $locked->consent_at;
            $order->consent_ip = $locked->consent_ip;
            $order->user_agent = $locked->user_agent;
            $order->customer_id = $locked->customer_id;
            $order->customer_group_id = $locked->customer_group_id;
            $order->customer_group_name = $locked->customer_group_name;
            $order->promo_link_id = $locked->promo_link_id;
            $order->source_order_id = $locked->id;
            $order->updated_by_user_id = $actor->id;
            // The flag of OE-04: the stock of the new order is counted when the shop counts stock at all.
            $order->stock_applied = $this->stock->trackingEnabled();
            $order->save();

            $plan = $this->build($order, $data, $actor, true);
            $plan->effects = $this->stock->applyItemChanges($order, $plan->stockChanges);
            $order->number = $this->numbers->next(OrderType::Order);
            $order->save();
            $this->finish($order, $plan);
            $this->persist($order, $plan);
            $order->version = 1;
            $order->syncOriginalAttribute('version');

            $from = $locked->status;
            $locked->status = OrderStatus::Closed;
            $locked->version = $locked->version + 1;
            $locked->updated_by_user_id = $actor->id;
            $locked->save();

            OrderStatusHistory::query()->create([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => OrderStatus::New,
                'user_id' => $actor->id,
                'comment' => __('order_edit.history.created_from_inquiry', ['number' => $locked->number]),
                'created_at' => now(),
            ]);
            OrderStatusHistory::query()->create([
                'order_id' => $locked->id,
                'from_status' => $from,
                'to_status' => OrderStatus::Closed,
                'user_id' => $actor->id,
                'comment' => __('order_edit.history.inquiry_closed', ['number' => $order->number]),
                'created_at' => now(),
            ]);

            $notify = $data->notifyCustomer && $this->notifications->notify_customer_new && filled($order->customer_email);
            DB::afterCommit(function () use ($order, $notify): void {
                app(NewRequestsCounter::class)->forget();

                if ($notify) {
                    // Only the customer is told: the shop made this order itself.
                    SendMailNotification::dispatch($order->id, SendMailNotification::CUSTOMER_PLACED);
                }
            });

            return $order->refresh()->load('items');
        }, self::DEADLOCK_ATTEMPTS);
    }

    /**
     * @throws ConcurrentEditException
     */
    private function assertVersion(Order $order, int $expectedVersion): void
    {
        if ((int) $order->version !== $expectedVersion) {
            $name = $order->updated_by_user_id === null ? null : User::query()->whereKey($order->updated_by_user_id)->value('name');

            throw new ConcurrentEditException($name, $order->updated_at);
        }
    }

    /**
     * @throws SalesException
     * @throws AuthorizationException
     */
    private function assertMayEdit(Order $order, User $actor): void
    {
        if (! $this->mayEdit($actor)) {
            throw new AuthorizationException;
        }

        if (! self::isEditable($order)) {
            throw new SalesException((string) self::blockReason($order));
        }
    }

    /**
     * Compares the form with the stored order and computes everything a save would write, except the stock effect.
     * `$creating` builds a new, still empty order (OE-12): no stored positions and no field diff to log.
     */
    private function build(Order $order, EditData $data, User $actor, bool $creating): EditPlan
    {
        $canPrices = $this->mayEditPrices($actor);
        $plan = new EditPlan;
        /** @var Collection<int, OrderItem> $stored */
        $stored = $creating ? new Collection : $order->items()->get()->keyBy('id');

        $requests = $this->merge($data, $stored);

        if ($requests === []) {
            throw new SalesException(__('order_edit.errors.empty'));
        }

        $products = Product::withTrashed()->whereIn('id', array_values(array_filter(array_unique(array_map(fn (array $r): ?int => $r['productId'], $requests)))))->get()->keyBy('id');
        $variantIds = array_values(array_filter(array_unique(array_map(fn (array $r): ?int => $r['variantId'], $requests))));
        $variants = $variantIds === [] ? new Collection : ProductVariant::withTrashed()->whereIn('id', $variantIds)->get()->keyBy('id');

        foreach ($variants as $variant) {
            $card = $products->get($variant->product_id);

            if ($card !== null) {
                $variant->setRelation('product', $card);
            }
        }

        $group = ($order->customer_group_id === null ? null : CustomerGroup::query()->find($order->customer_group_id)) ?? CustomerGroup::guest();
        $units = [];

        foreach ($requests as $key => $request) {
            $units[$key] = $this->unit($request, $products, $variants);
        }

        $priceables = array_values(array_filter($units));
        $book = $priceables === [] ? null : $this->resolver->preload($priceables, $group);

        foreach ($requests as $key => $request) {
            $plan->lines[] = $this->line($request, $units[$key], $products->get($request['productId'] ?? 0), $group, $book, $canPrices);
        }

        $keptIds = array_filter(array_map(fn (PlannedLine $line): ?int => $line->item?->id, $plan->lines));
        $plan->removed = array_values($stored->filter(fn (OrderItem $item): bool => ! in_array($item->id, $keptIds, true))->all());

        $this->planStock($plan);
        $this->planTotals($order, $data, $plan, $group, $canPrices);
        $this->planFields($order, $data, $plan, $canPrices, $creating);

        if (! $creating) {
            $this->planItemChanges($plan);
        }

        return $plan;
    }

    /**
     * The positions of the form merged by product and variant and attached to the stored rows (OE-13).
     *
     * @param  Collection<int, OrderItem>  $stored
     * @return array<string, array{item: OrderItem|null, productId: int|null, variantId: int|null, qty: int, price: int|null, recalculate: bool}>
     */
    private function merge(EditData $data, Collection $stored): array
    {
        $byKey = [];

        foreach ($stored as $item) {
            $byKey[$this->key($item->product_id, $item->variant_id, $item->id)] ??= $item;
        }

        $requests = [];

        foreach ($data->items as $edit) {
            if ($edit->itemId !== null) {
                $item = $stored->get($edit->itemId) ?? throw new SalesException(__('order_edit.errors.item_missing'));
                $productId = $item->product_id;
                $variantId = $item->variant_id;
            } else {
                $productId = $edit->productId ?? throw new SalesException(__('order_edit.errors.product_missing'));
                $variantId = $edit->variantId;
                $item = $byKey[$this->key($productId, $variantId, 0)] ?? null;
            }

            $key = $this->key($productId, $variantId, $item->id ?? 0);

            if (isset($requests[$key])) {
                $requests[$key]['qty'] += $edit->qty;
                $requests[$key]['price'] ??= $edit->price;
                $requests[$key]['recalculate'] = $requests[$key]['recalculate'] || $edit->recalculate;
                $requests[$key]['item'] ??= $item;

                continue;
            }

            $requests[$key] = [
                'item' => $item,
                'productId' => $productId,
                'variantId' => $variantId,
                'qty' => $edit->qty,
                'price' => $edit->price,
                'recalculate' => $edit->recalculate,
            ];
        }

        return $requests;
    }

    private function key(?int $productId, ?int $variantId, int $itemId): string
    {
        return $productId === null ? 'row:'.$itemId : $productId.':'.($variantId ?? 0);
    }

    /**
     * The sellable unit of a position (a product without variants or the variant), null when it is gone.
     *
     * @param  array{item: OrderItem|null, productId: int|null, variantId: int|null, qty: int, price: int|null, recalculate: bool}  $request
     * @param  Collection<int, Product>  $products
     * @param  Collection<int, ProductVariant>  $variants
     */
    private function unit(array $request, Collection $products, Collection $variants): Product|ProductVariant|null
    {
        $item = $request['item'];

        if ($request['productId'] === null) {
            return null;
        }

        $product = $products->get($request['productId']);

        if ($product === null) {
            throw new SalesException(__('order_edit.errors.product_missing'));
        }

        $variant = $request['variantId'] === null ? null : $variants->get($request['variantId']);

        if ($item === null) {
            // OE-13: a new position is an active product (and an active variant of it); an inactive one that is in the order stays editable.
            if ($product->trashed() || ! $product->is_active) {
                throw new SalesException(__('order_edit.errors.product_inactive', ['name' => $product->name]));
            }

            if ($product->has_variants) {
                if ($request['variantId'] === null) {
                    throw new SalesException(__('variant_storefront.errors.required', ['name' => $product->name]));
                }

                if ($variant === null || (int) $variant->product_id !== (int) $product->id || $variant->trashed() || ! $variant->is_active) {
                    throw new SalesException(__('variant_storefront.errors.unavailable', ['name' => $product->name]));
                }
            } elseif ($request['variantId'] !== null) {
                throw new SalesException(__('order_edit.errors.variant_mismatch', ['name' => $product->name]));
            }
        }

        return $variant ?? ($request['variantId'] === null ? $product : null);
    }

    /**
     * One position after the edit: quantity, price and where the price comes from (OE-03).
     *
     * @param  array{item: OrderItem|null, productId: int|null, variantId: int|null, qty: int, price: int|null, recalculate: bool}  $request
     */
    private function line(array $request, Product|ProductVariant|null $unit, ?Product $product, CustomerGroup $group, ?PriceBook $book, bool $canPrices): PlannedLine
    {
        $item = $request['item'];
        $variant = $unit instanceof ProductVariant ? $unit : null;
        $qty = $request['qty'];
        $name = $item !== null ? $item->name : ($product->name ?? '');
        $label = $item !== null ? $item->displayName() : $this->snapshotName($product, $variant);

        if ($qty < 1 || $qty > QtyLimit::MAX) {
            throw new SalesException(__('order_edit.errors.qty_invalid', ['name' => $label, 'max' => QtyLimit::MAX]));
        }

        if ($request['price'] !== null && $request['price'] < 0) {
            throw new SalesException(__('order_edit.errors.price_invalid', ['name' => $label]));
        }

        $resolved = $unit === null ? null : $this->resolver->resolve($unit, $group, $qty, $book);

        if ($item === null) {
            if ($resolved === null || $product === null) {
                throw new SalesException(__('order_edit.errors.product_missing'));
            }

            if ($request['price'] !== null && $request['price'] !== $resolved->unitPrice) {
                $this->assertMayPrice($canPrices);

                return new PlannedLine(null, $product, $variant, $qty, $request['price'], PriceSource::Manual, null, $resolved->retailPrice, 0, null, $name, $label);
            }

            if ($product->price_on_request && ($request['price'] === null)) {
                // «Цена по запросу»: the price list has no price, the manager must type one.
                $this->assertMayPrice($canPrices);

                throw new SalesException(__('order_edit.errors.price_required', ['name' => $label]));
            }

            return new PlannedLine(null, $product, $variant, $qty, $resolved->unitPrice, $resolved->source, $resolved->tierMinQty, $resolved->retailPrice, 0, null, $name, $label);
        }

        $oldPrice = $item->price === null ? 0 : Money::fromRubles($item->price);
        $retail = $item->retail_price === null ? null : Money::fromRubles($item->retail_price);

        if ($request['recalculate']) {
            $this->assertMayPrice($canPrices);

            if ($resolved === null) {
                throw new SalesException(__('order_edit.errors.product_missing'));
            }

            return new PlannedLine($item, $product, $variant, $qty, $resolved->unitPrice, $resolved->source, $resolved->tierMinQty, $resolved->retailPrice, $item->qty, $oldPrice, $name, $label);
        }

        if ($request['price'] !== null && $request['price'] !== $oldPrice) {
            $this->assertMayPrice($canPrices);

            return new PlannedLine($item, $product, $variant, $qty, $request['price'], PriceSource::Manual, null, $retail, $item->qty, $oldPrice, $name, $label);
        }

        // OE-03: a changed quantity keeps the stored price.
        return new PlannedLine($item, $product, $variant, $qty, $oldPrice, $item->price_source, $item->tier_min_qty, $retail, $item->qty, $oldPrice, $name, $label);
    }

    private function snapshotName(?Product $product, ?ProductVariant $variant): string
    {
        if ($product === null) {
            return '';
        }

        $snapshot = $this->snapshots->for($product, $variant);

        return $snapshot['variant_label'] !== null ? $snapshot['name'].' ('.$snapshot['variant_label'].')' : $snapshot['name'];
    }

    /**
     * @throws AuthorizationException
     */
    private function assertMayPrice(bool $canPrices): void
    {
        if (! $canPrices) {
            throw new AuthorizationException(__('order_edit.errors.prices_forbidden'));
        }
    }

    private function planStock(EditPlan $plan): void
    {
        foreach ($plan->lines as $index => $line) {
            if ($line->product === null) {
                continue;
            }

            $plan->stockChanges[] = new StockChange($index, $line->product->id, $line->variant?->id, $line->oldQty, $line->qty, $line->item->stock_applied_qty ?? 0);
        }

        foreach ($plan->removed as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $plan->stockChanges[] = new StockChange('r'.$item->id, (int) $item->product_id, $item->variant_id, $item->qty, 0, $item->stock_applied_qty);
        }
    }

    /**
     * Totals in kopecks (OE-06): items, promo (recalculated on the new rows), delivery, the manager's discount.
     */
    private function planTotals(Order $order, EditData $data, EditPlan $plan, CustomerGroup $group, bool $canPrices): void
    {
        $plan->oldItemsTotal = $order->items_total === null ? 0 : Money::fromRubles($order->items_total);
        $plan->oldTotal = $order->total === null ? 0 : Money::fromRubles($order->total);
        $plan->oldPromoDiscount = Money::fromRubles((string) $order->promo_discount);
        $plan->newItemsTotal = array_sum(array_map(fn (PlannedLine $line): int => $line->total(), $plan->lines));

        if ($data->deliveryPrice < 0 || $data->manualDiscount < 0) {
            throw new SalesException(__('order_edit.errors.amount_invalid'));
        }

        $plan->deliveryPrice = $data->deliveryPrice;
        $plan->manualDiscount = $data->manualDiscount;

        if ($data->manualDiscount !== Money::fromRubles((string) $order->manual_discount)) {
            $this->assertMayPrice($canPrices);
        }

        $itemsChanged = $plan->removed !== [];

        foreach ($plan->lines as $line) {
            $itemsChanged = $itemsChanged || $line->isNew() || $line->qty !== $line->oldQty || $line->priceChanged();
        }

        if ($order->promo_code !== null && $order->promo_code !== '' && ($order->promo_code_id === null || ! $itemsChanged)) {
            // The code was deleted after the order was placed (it cannot be evaluated), or the positions are as they were
            // (a re-save must not move the discount): the stored discount stays, capped by the items total.
            $plan->promoKept = true;
            $plan->promoDiscount = min($plan->oldPromoDiscount, $plan->newItemsTotal);

            foreach ($plan->lines as $line) {
                $line->promoDiscount = $line->item === null ? 0 : Money::fromRubles((string) $line->item->promo_discount);
            }
        } elseif ($order->promo_code_id !== null) {
            $promoLines = [];

            foreach ($plan->lines as $index => $line) {
                if ($line->product === null) {
                    continue;
                }

                $promoLines[] = PromoLine::make($index, $line->product, $line->variant?->id, $line->qty, $line->price, $this->promoOldPrice($line, $group));
            }

            $plan->promo = $this->promos->recalculateForOrder($order, $promoLines);
            $plan->promoDiscount = $plan->promo->discount;

            foreach ($plan->lines as $index => $line) {
                $line->promoDiscount = $plan->promo->discountFor($index);
            }
        }

        $plan->newTotal = max(0, $plan->newItemsTotal - $plan->promoDiscount - $plan->manualDiscount + $plan->deliveryPrice);
    }

    /**
     * PM-05: the price before the shop's own promotion, when the line is still sold at the price list price of its group.
     */
    private function promoOldPrice(PlannedLine $line, CustomerGroup $group): ?int
    {
        if ($line->source === PriceSource::Manual || $line->product === null) {
            return null;
        }

        $unit = $line->variant ?? $line->product;
        $resolved = $this->resolver->resolve($unit, $group, $line->qty);

        return $this->isListPrice($line, $resolved) ? PromoLine::oldPriceOf($resolved) : null;
    }

    private function isListPrice(PlannedLine $line, ResolvedPrice $resolved): bool
    {
        return $resolved->unitPrice === $line->price;
    }

    /**
     * The order columns that change (contacts, delivery, discount, totals) and their log rows (OE-07).
     */
    private function planFields(Order $order, EditData $data, EditPlan $plan, bool $canPrices, bool $creating): void
    {
        $name = trim($data->customerName);

        if ($name === '' || mb_strlen($name) > 255) {
            throw new SalesException(__('order_edit.errors.name_invalid'));
        }

        $phone = $data->customerPhone === $order->customer_phone
            ? $order->customer_phone
            : (PhoneNormalizer::normalize($data->customerPhone) ?? throw new SalesException(__('order_edit.errors.phone_invalid')));

        $email = $this->blankToNull($data->customerEmail);

        if ($email !== null && $email !== $order->customer_email) {
            $email = EmailNormalizer::normalize($email) ?? throw new SalesException(__('order_edit.errors.email_invalid'));
        }

        $method = $this->blankToNull($data->deliveryMethod);

        if (($method !== null && mb_strlen($method) > 255) || mb_strlen((string) $data->deliveryAddress) > 2000 || mb_strlen((string) $data->customerComment) > 5000 || mb_strlen((string) $data->managerComment) > 5000) {
            throw new SalesException(__('order_edit.errors.too_long'));
        }

        // Column => [new value, change kind, whether it is money (kopecks compared)].
        $fields = [
            'customer_name' => [$name, OrderChangeKind::Field],
            'customer_phone' => [$phone, OrderChangeKind::Field],
            'customer_email' => [$email, OrderChangeKind::Field],
            'customer_comment' => [$this->blankToNull($data->customerComment), OrderChangeKind::Field],
            'manager_comment' => [$this->blankToNull($data->managerComment), OrderChangeKind::Field],
            'delivery_method' => [$method, OrderChangeKind::Delivery],
            'delivery_address' => [$this->blankToNull($data->deliveryAddress), OrderChangeKind::Delivery],
        ];

        foreach ($fields as $column => [$new, $kind]) {
            $old = $order->{$column};

            if ($creating || $old !== $new) {
                $plan->orderFields[$column] = $new;
            }

            if (! $creating && $old !== $new) {
                $plan->changes[] = new PlannedChange($kind, $column, null, null, $old, $new);
            }
        }

        $money = [
            'delivery_price' => [$plan->deliveryPrice, Money::fromRubles($order->delivery_price), OrderChangeKind::Delivery],
            'manual_discount' => [$plan->manualDiscount, Money::fromRubles((string) $order->manual_discount), OrderChangeKind::Discount],
            'promo_discount' => [$plan->promoDiscount, $plan->oldPromoDiscount, OrderChangeKind::Discount],
            'items_total' => [$plan->newItemsTotal, $plan->oldItemsTotal, OrderChangeKind::Field],
            'total' => [$plan->newTotal, $plan->oldTotal, OrderChangeKind::Field],
        ];

        foreach ($money as $column => [$new, $old, $kind]) {
            $plan->orderFields[$column] = Money::toRubles($new);

            if (! $creating && $new !== $old) {
                $plan->changes[] = new PlannedChange($kind, $column, null, null, Money::toRubles($old), Money::toRubles($new));
            }
        }
    }

    /**
     * The log rows of the positions: added, removed, quantity and price changed (OE-07).
     */
    private function planItemChanges(EditPlan $plan): void
    {
        foreach ($plan->removed as $item) {
            $plan->changes[] = new PlannedChange(
                OrderChangeKind::ItemRemoved,
                null,
                $item->id,
                $this->itemLabel($item->displayName(), $item->sku),
                $this->lineText($item->qty, $item->price === null ? 0 : Money::fromRubles($item->price)),
                null,
            );
        }

        foreach ($plan->lines as $line) {
            $label = $this->itemLabel($line->label, $line->item->sku ?? $line->variant->sku ?? $line->product->sku ?? '');

            if ($line->isNew()) {
                $plan->changes[] = new PlannedChange(OrderChangeKind::ItemAdded, null, null, $label, null, $this->lineText($line->qty, $line->price));

                continue;
            }

            if ($line->qty !== $line->oldQty) {
                $plan->changes[] = new PlannedChange(OrderChangeKind::ItemQty, 'qty', $line->item?->id, $label, (string) $line->oldQty, (string) $line->qty);
            }

            if ($line->priceChanged()) {
                $plan->changes[] = new PlannedChange(OrderChangeKind::ItemPrice, 'price', $line->item?->id, $label, Money::toRubles((int) $line->oldPrice), Money::toRubles($line->price));
            }
        }
    }

    private function lineText(int $qty, int $price): string
    {
        return $qty.' × '.Money::toRubles($price);
    }

    private function itemLabel(string $name, string $sku): string
    {
        return Str::limit($sku === '' ? $name : $name.' · '.$sku, 600, '');
    }

    /**
     * Attaches the stock effects to the lines, adds the stock rows of the log and the warnings (OE-10).
     */
    private function finish(Order $order, EditPlan $plan): void
    {
        foreach ($plan->lines as $index => $line) {
            $line->effect = $plan->effectFor($index);
        }

        $column = $order->type === OrderType::Reservation ? 'reserved_qty' : 'stock_qty';

        foreach ($plan->effects as $effect) {
            $delta = $order->type === OrderType::Reservation ? $effect->reservedDelta : $effect->stockDelta;

            if ($delta === 0) {
                continue;
            }

            $itemId = null;
            $label = $effect->name;

            if (is_string($effect->key)) {
                $removed = collect($plan->removed)->firstWhere('id', (int) substr($effect->key, 1));
                $itemId = $removed?->id;
                $label = $removed !== null ? $this->itemLabel($removed->displayName(), $removed->sku) : $label;
            } elseif (isset($plan->lines[$effect->key])) {
                $line = $plan->lines[$effect->key];
                $itemId = $line->item?->id;
                $label = $this->itemLabel($line->label, $line->item->sku ?? $line->variant->sku ?? $line->product->sku ?? '');
            }

            $plan->changes[] = new PlannedChange(OrderChangeKind::Stock, $column, $itemId, $label, null, ($delta > 0 ? '+' : '').$delta);
        }

        $this->warn($plan);
    }

    /**
     * OE-10 warnings: a price below the purchase price, a discount above the threshold, an unavailable quantity.
     */
    private function warn(EditPlan $plan): void
    {
        foreach ($plan->lines as $line) {
            if (! $line->isNew() && ! $line->priceChanged()) {
                continue;
            }

            $cost = $line->variant->cost_price ?? $line->product->cost_price;

            if ($cost !== null && Money::fromRubles($cost) > $line->price) {
                $plan->warnings[] = __('order_edit.warnings.below_cost', ['name' => $line->label, 'price' => Money::format($line->price), 'cost' => Money::format(Money::fromRubles($cost))]);
            }
        }

        $threshold = $this->notifications->order_discount_warning_percent;

        if ($threshold > 0 && $plan->manualDiscount > 0 && $plan->newItemsTotal > 0 && $plan->manualDiscount * 100 > $plan->newItemsTotal * $threshold) {
            $plan->warnings[] = __('order_edit.warnings.big_discount', ['percent' => $threshold]);
        }

        foreach ($plan->effects as $effect) {
            if ($effect->shortage !== null) {
                $plan->warnings[] = __('sales.errors.insufficient_stock', ['name' => $effect->name, 'available' => $effect->shortage]);
            }
        }
    }

    /**
     * Writes positions, order columns and the promo redemption (24.5: «update order_items, orders fields»).
     */
    private function persist(Order $order, EditPlan $plan): void
    {
        if ($plan->removed !== []) {
            OrderItem::query()->whereKey(array_map(fn (OrderItem $item): int => $item->id, $plan->removed))->delete();
        }

        foreach ($plan->lines as $index => $line) {
            $item = $line->item ?? new OrderItem;

            if ($line->isNew()) {
                $item->forceFill($this->snapshots->for($line->product ?? throw new SalesException(__('order_edit.errors.product_missing')), $line->variant) + ['order_id' => $order->id]);
            }

            $item->forceFill([
                'qty' => $line->qty,
                'price' => Money::toRubles($line->price),
                'total' => Money::toRubles($line->total()),
                'price_source' => $line->source,
                'tier_min_qty' => $line->tierMinQty,
                'retail_price' => $line->retailPrice === null ? null : Money::toRubles($line->retailPrice),
                'promo_discount' => Money::toRubles($line->promoDiscount),
                'stock_applied_qty' => $plan->effectFor($index)->appliedQty ?? min($item->stock_applied_qty ?? 0, $line->qty),
            ])->save();
        }

        $order->forceFill($plan->orderFields)->save();

        if ($order->promo_code_id !== null) {
            PromoRedemption::query()
                ->where('order_id', $order->id)
                ->whereNull('released_at')
                ->update(['discount_amount' => Money::toRubles($plan->promoDiscount)]);
        }
    }

    private function writeLog(Order $order, EditPlan $plan, string $batch, User $actor): void
    {
        $now = now();

        foreach ($plan->changes as $change) {
            OrderChange::query()->create([
                'order_id' => $order->id,
                'batch_uuid' => $batch,
                'kind' => $change->kind,
                'field' => $change->field,
                'order_item_id' => $change->itemId,
                'item_label' => $change->label,
                'old_value' => $change->old,
                'new_value' => $change->new,
                'user_id' => $actor->id,
                'created_at' => $now,
            ]);
        }
    }

    /**
     * The optimistic lock (OE-08): the version moves only when it is still the one the form was opened with.
     *
     * @throws ConcurrentEditException
     */
    private function bumpVersion(Order $order, int $expectedVersion, User $actor): void
    {
        $affected = DB::table('orders')
            ->where('id', $order->id)
            ->where('version', $expectedVersion)
            ->update(['version' => $expectedVersion + 1, 'updated_by_user_id' => $actor->id, 'updated_at' => now()]);

        if ($affected !== 1) {
            throw new ConcurrentEditException($actor->name, now());
        }
    }

    /**
     * How a stock effect reads in the preview (OE-10): «−2 со склада», «снять 1 из резерва», «под заказ: 1», or null.
     */
    public static function stockNote(StockEffect $effect, OrderType $type, int $qty): ?string
    {
        $parts = [];

        if ($type === OrderType::Reservation) {
            if ($effect->reservedDelta > 0) {
                $parts[] = __('order_edit.stock.hold', ['qty' => $effect->reservedDelta]);
            } elseif ($effect->reservedDelta < 0) {
                $parts[] = __('order_edit.stock.release', ['qty' => abs($effect->reservedDelta)]);
            }
        } elseif ($effect->stockDelta < 0) {
            $parts[] = __('order_edit.stock.deduct', ['qty' => abs($effect->stockDelta)]);
        } elseif ($effect->stockDelta > 0) {
            $parts[] = __('order_edit.stock.restore', ['qty' => $effect->stockDelta]);
        }

        if ($effect->tracked && $effect->appliedQty < $qty) {
            $parts[] = __('order_edit.stock.backorder', ['qty' => $qty - $effect->appliedQty]);
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
