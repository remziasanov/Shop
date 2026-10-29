<?php

declare(strict_types=1);

use App\Enums\CustomerNotificationType;
use App\Enums\OrderStatus;
use App\Models\CustomerNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Customers\CustomerNotificationService;
use App\Services\Orders\OrderService;
use App\Services\System\Cleanup;
use Illuminate\Support\Facades\Storage;

/**
 * CA-18 (TZ 17.3): notifications of the cabinet: events, unread counter in the header, 90-day retention.
 */
beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
    $this->customer = makeCustomer();
    $this->service = app(CustomerNotificationService::class);
});

it('creates notifications, counts the unread ones and marks them read, only for the owner', function (): void {
    $other = makeCustomer();
    $first = $this->service->notify($this->customer, CustomerNotificationType::OrderStatus, 'Заголовок', 'Текст', '/account/orders');
    $this->service->notify($this->customer, CustomerNotificationType::WholesaleDecision, 'Опт', 'Одобрено');
    $this->service->notify($other, CustomerNotificationType::OrderStatus, 'Чужое', 'Чужой текст');

    expect($this->service->unreadCount($this->customer))->toBe(2)
        ->and($this->service->unreadCount($other))->toBe(1)
        // Another customer cannot mark this notification.
        ->and($this->service->markRead($other, $first->id))->toBeFalse()
        ->and($first->fresh()->read_at)->toBeNull()
        ->and($this->service->markRead($this->customer, $first->id))->toBeTrue()
        ->and($this->service->unreadCount($this->customer))->toBe(1)
        ->and($this->service->markAllRead($this->customer))->toBe(1)
        ->and($this->service->unreadCount($this->customer))->toBe(0)
        ->and($this->service->unreadCount($other))->toBe(1);
});

it('does not create the same notification twice for one dedupe key and skips anonymized customers', function (): void {
    $a = $this->service->notify($this->customer, CustomerNotificationType::ReservationExpiring, 'T', 'B', null, 'k1');
    $b = $this->service->notify($this->customer, CustomerNotificationType::ReservationExpiring, 'T', 'B', null, 'k1');

    expect($a)->not->toBeNull()->and($b)->toBeNull()->and(CustomerNotification::query()->count())->toBe(1);

    $gone = makeCustomer(['anonymized_at' => now()]);
    expect($this->service->notify($gone, CustomerNotificationType::OrderStatus, 'T', 'B'))->toBeNull();
});

it('notifies about a status change of a request under an account, like the e-mail does', function (): void {
    $order = Order::factory()->create(['customer_id' => $this->customer->id]);
    $guest = Order::factory()->create(['customer_id' => null]);

    app(OrderService::class)->changeStatus($order, OrderStatus::Confirmed);
    app(OrderService::class)->changeStatus($guest, OrderStatus::Confirmed);
    // A status that is not mailed to the customer does not appear in the cabinet either.
    app(OrderService::class)->changeStatus($order, OrderStatus::Shipped);

    $notification = CustomerNotification::query()->firstOrFail();

    expect(CustomerNotification::query()->count())->toBe(1)
        ->and($notification->customer_id)->toBe($this->customer->id)
        ->and($notification->type)->toBe('order_status')
        ->and($notification->body)->toContain('Подтверждён')
        ->and($notification->url)->toBe('/account/orders/'.$order->uuid);
});

it('notifies when the system expires a reservation', function (): void {
    $product = sellable();
    $order = Order::factory()->reservation()->create(['customer_id' => $this->customer->id, 'reserved_until' => now()->subHour()]);
    OrderItem::factory()->forProduct($product, 1)->create(['order_id' => $order->id]);

    app(OrderService::class)->expireReservations();

    $notification = CustomerNotification::query()->firstOrFail();
    expect($notification->type)->toBe('reservation_expired')->and($notification->title)->toContain($order->number);
});

it('warns once before a reservation expires and again after the term is prolonged', function (): void {
    $order = Order::factory()->reservation()->create(['customer_id' => $this->customer->id, 'reserved_until' => now()->addHours(3)]);
    Order::factory()->reservation()->create(['customer_id' => $this->customer->id, 'reserved_until' => now()->addHours(30)]);
    Order::factory()->reservation()->create(['customer_id' => null, 'reserved_until' => now()->addHours(3)]);

    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();
    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();

    expect(CustomerNotification::query()->where('type', 'reservation_expiring')->count())->toBe(1);

    $order->forceFill(['reserved_until' => now()->addHours(4)])->save();
    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();

    expect(CustomerNotification::query()->where('type', 'reservation_expiring')->count())->toBe(2);
});

it('shows the cabinet page, marks read and follows the link, and refuses foreign notifications', function (): void {
    signInCustomer($this, $this->customer);
    $mine = CustomerNotification::factory()->create(['customer_id' => $this->customer->id, 'title' => 'Моё уведомление', 'url' => '/account/orders']);
    $foreign = CustomerNotification::factory()->create(['customer_id' => makeCustomer()->id, 'title' => 'Чужое уведомление']);

    $this->get(route('customer.notifications'))->assertOk()->assertSee('Моё уведомление')->assertDontSee('Чужое уведомление');

    $this->post(route('customer.notifications.read', $foreign->id))->assertNotFound();
    expect($foreign->fresh()->read_at)->toBeNull();

    $this->post(route('customer.notifications.read', $mine->id))->assertRedirect('/account/orders');
    expect($mine->fresh()->read_at)->not->toBeNull();
});

it('never redirects to an external address from a notification', function (): void {
    signInCustomer($this, $this->customer);
    $evil = CustomerNotification::factory()->create(['customer_id' => $this->customer->id, 'url' => '//evil.example.com/x']);

    $this->post(route('customer.notifications.read', $evil->id))->assertRedirect(route('customer.notifications'));
});

it('marks everything read', function (): void {
    signInCustomer($this, $this->customer);
    CustomerNotification::factory()->count(3)->create(['customer_id' => $this->customer->id]);

    $this->post(route('customer.notifications.read-all'))->assertRedirect(route('customer.notifications'));

    expect($this->service->unreadCount($this->customer))->toBe(0);
});

it('shows the unread counter in the header and on the cabinet page, and nothing for a guest', function (): void {
    CustomerNotification::factory()->count(2)->create(['customer_id' => $this->customer->id]);

    $this->get(route('home'))->assertOk()->assertDontSee('data-unread-badge', false);

    signInCustomer($this, $this->customer);
    $this->get(route('home'))->assertOk()->assertSee('data-unread-badge', false);
    $this->get(route('account'))->assertOk()->assertSee('data-unread-count', false);

    $this->service->markAllRead($this->customer);
    $this->get(route('home'))->assertDontSee('data-unread-badge', false);
});

it('deletes notifications older than 90 days and keeps the newer ones', function (): void {
    CustomerNotification::factory()->create(['customer_id' => $this->customer->id, 'created_at' => now()->subDays(CustomerNotification::RETENTION_DAYS + 1)]);
    CustomerNotification::factory()->create(['customer_id' => $this->customer->id, 'created_at' => now()->subDays(CustomerNotification::RETENTION_DAYS - 1)]);

    expect($this->service->purgeOlderThan())->toBe(1)
        ->and(CustomerNotification::query()->count())->toBe(1);
});

it('runs the retention as a step of the nightly cleanup', function (): void {
    CustomerNotification::factory()->create(['customer_id' => $this->customer->id, 'created_at' => now()->subDays(120)]);
    Storage::fake('local');

    $summary = app(Cleanup::class)->run(microtime(true) + 30);

    expect($summary['customer_notifications'])->toBe(1)->and(CustomerNotification::query()->count())->toBe(0);
});
