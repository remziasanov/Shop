<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Jobs\SendMailNotification;
use App\Jobs\SendOrderNotifications;
use App\Jobs\SendTelegramNotification;
use App\Mail\NewRequestShopMail;
use App\Mail\OrderPlacedCustomerMail;
use App\Mail\OrderStatusChangedCustomerMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Notifications\TelegramException;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Orders\OrderService;
use App\Settings\NotificationSettings;
use App\Support\PiiMask;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $values
 */
function notif(array $values = []): NotificationSettings
{
    $settings = app(NotificationSettings::class);
    $settings->notify_emails = ['shop@example.com'];
    $settings->telegram_enabled = true;
    $settings->telegram_bot_token = '123456:SECRET-token_abc';
    $settings->telegram_chat_id = '-1001';

    foreach (['notify_shop_email', 'notify_shop_telegram', 'notify_customer_new', 'notify_customer_status', 'notify_reservation_expiring', 'notify_reservation_expired', 'notify_event_order', 'notify_event_reservation', 'notify_event_inquiry'] as $toggle) {
        $settings->{$toggle} = true;
    }

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

function newOrder(array $attributes = [], string $state = 'order'): Order
{
    $order = Order::factory()->{$state}()->create(array_merge(['customer_email' => 'buyer@example.com'], $attributes));
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Чайник', 'qty' => 2, 'price' => '500.00', 'total' => '1000.00']);
    $order->forceFill(['items_total' => '1000.00', 'total' => '1000.00'])->save();

    return $order;
}

it('queues the notification job when a request is created, after commit (NT-01)', function (): void {
    Queue::fake();
    sales(['stock_tracking' => false]);

    app(OrderService::class)->createFromCart(cartWith(sellable()), checkoutData());

    Queue::assertPushed(SendOrderNotifications::class, fn (SendOrderNotifications $job): bool => $job->event === 'created' && $job->afterCommit === true);
});

it('creating a request never fails because of notifications (NT-01)', function (): void {
    sales(['stock_tracking' => false]);
    Exceptions::fake();
    app()->instance('queue', Mockery::mock(app('queue'))->shouldReceive('connection')->andThrow(new RuntimeException('queue down'))->getMock());

    $order = app(OrderService::class)->createFromCart(cartWith(sellable()), checkoutData());

    expect($order->exists)->toBeTrue()
        ->and(Order::query()->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'queue down');
});

it('sends customer and shop emails and Telegram per toggles', function (): void {
    notif();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $order = newOrder();

    OrderCreated::dispatch($order);

    Mail::assertSent(OrderPlacedCustomerMail::class, fn ($mail): bool => $mail->hasTo('buyer@example.com'));
    Mail::assertSent(NewRequestShopMail::class, fn ($mail): bool => $mail->hasTo('shop@example.com'));
    Http::assertSentCount(1);
});

it('respects the toggles and skips the customer email when absent', function (): void {
    notif(['notify_customer_new' => false, 'notify_shop_email' => false, 'notify_shop_telegram' => false]);
    Mail::fake();
    Http::fake();

    OrderCreated::dispatch(newOrder());

    Mail::assertNothingSent();
    Http::assertNothingSent();

    notif();
    OrderCreated::dispatch(newOrder(['customer_email' => null]));

    Mail::assertNotSent(OrderPlacedCustomerMail::class);
    Mail::assertSent(NewRequestShopMail::class);
});

it('respects the per-type toggle', function (): void {
    notif(['notify_event_inquiry' => false]);
    Mail::fake();
    Http::fake();

    OrderCreated::dispatch(newOrder([], 'inquiry'));

    Mail::assertNotSent(NewRequestShopMail::class);
    Mail::assertSent(OrderPlacedCustomerMail::class);
    Http::assertNothingSent();
});

it('sends escaped HTML to Telegram (NT-02)', function (): void {
    notif();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $order = newOrder(['customer_name' => 'Иван <b>&</b> Co']);
    $order->items()->first()->update(['name' => '<i>Чайник</i>']);

    OrderCreated::dispatch($order);

    Http::assertSent(function (Request $request) use ($order): bool {
        $data = $request->data();

        return str_contains($request->url(), '/bot123456:SECRET-token_abc/sendMessage')
            && $data['parse_mode'] === 'HTML'
            && $data['chat_id'] === '-1001'
            && $data['disable_web_page_preview'] === true
            && str_contains($data['text'], 'Иван &lt;b&gt;&amp;&lt;/b&gt; Co')
            && str_contains($data['text'], '&lt;i&gt;Чайник&lt;/i&gt; × 2')
            && ! str_contains($data['text'], '<i>')
            && str_contains($data['text'], 'Z-')
            && str_contains($data['text'], '/orders/'.$order->uuid);
    });
});

it('includes the reservation deadline in the Telegram message', function (): void {
    notif();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $order = newOrder(['reserved_until' => now()->addDay()->setTime(15, 30)], 'reservation');

    OrderCreated::dispatch($order);

    Http::assertSent(fn (Request $r): bool => str_contains($r->data()['text'], $order->reserved_until->format('d.m.Y H:i')));
});

it('a failing Telegram job fails and retries while the emails still go out', function (): void {
    notif();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 500)]);
    $order = newOrder();

    $job = new SendTelegramNotification($order->id, SendTelegramNotification::NEW_REQUEST);
    expect(fn () => $job->handle(app(TelegramNotifier::class)))->toThrow(TelegramException::class)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([60, 300]);

    // Through the sync queue the failing channel throws, but the mails are queued before and sent independently.
    $mails = new SendMailNotification($order->id, SendMailNotification::CUSTOMER_PLACED);
    $mails->handle();
    Mail::assertSent(OrderPlacedCustomerMail::class);
});

it('never exposes the token in the Telegram exception', function (): void {
    notif();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Unauthorized 123456:SECRET-token_abc'], 401)]);

    try {
        app(TelegramNotifier::class)->send('x');
    } catch (TelegramException $e) {
        expect($e->getMessage())->not->toContain('SECRET-token_abc')->and($e->getMessage())->toContain('401');

        return;
    }

    $this->fail('Expected exception');
});

it('sends status emails only for the listed statuses', function (string $status, bool $sent): void {
    notif();
    Mail::fake();
    Http::fake();
    $order = newOrder();

    OrderStatusChanged::dispatch($order, OrderStatus::New, OrderStatus::from($status), null);

    $sent ? Mail::assertSent(OrderStatusChangedCustomerMail::class, fn ($m): bool => $m->hasTo('buyer@example.com') && $m->status->value === $status)
        : Mail::assertNothingSent();
})->with([
    'confirmed' => ['confirmed', true],
    'ready' => ['ready', true],
    'cancelled' => ['cancelled', true],
    'reserved' => ['reserved', true],
    'shipped' => ['shipped', false],
    'completed' => ['completed', false],
    'in_progress' => ['in_progress', false],
]);

it('does not send status emails when the toggle is off or the email is missing', function (): void {
    notif(['notify_customer_status' => false]);
    Mail::fake();

    OrderStatusChanged::dispatch(newOrder(), OrderStatus::New, OrderStatus::Confirmed, null);
    Mail::assertNothingSent();

    notif();
    OrderStatusChanged::dispatch(newOrder(['customer_email' => null]), OrderStatus::New, OrderStatus::Confirmed, null);
    Mail::assertNothingSent();
});

it('sends Telegram when a reservation expires automatically', function (): void {
    notif();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $order = newOrder(['reserved_until' => now()->subHour()], 'reservation');

    OrderStatusChanged::dispatch($order, OrderStatus::New, OrderStatus::Expired, null);

    Http::assertSent(fn (Request $r): bool => str_contains($r->data()['text'], 'Резерв истёк автоматически'));
    Mail::assertNothingSent();
});

it('does not send the expired Telegram when its toggle is off', function (): void {
    notif(['notify_reservation_expired' => false]);
    Http::fake();

    OrderStatusChanged::dispatch(newOrder([], 'reservation'), OrderStatus::New, OrderStatus::Expired, null);

    Http::assertNothingSent();
});

it('expire-reservations command triggers the Telegram notice end to end', function (): void {
    notif();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    newOrder(['reserved_until' => now()->subHour()], 'reservation');

    $this->artisan('shop:expire-reservations')->assertSuccessful();

    Http::assertSentCount(1);
});

it('notifies about expiring reservations once each', function (): void {
    notif();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $soon = newOrder(['reserved_until' => now()->addHours(3)], 'reservation');
    newOrder(['reserved_until' => now()->addHours(30)], 'reservation');
    newOrder(['reserved_until' => now()->addHours(2), 'status' => OrderStatus::Cancelled], 'reservation');

    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();
    Http::assertSentCount(1);
    expect($soon->fresh()->expiring_notified_at)->not->toBeNull();

    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();
    Http::assertSentCount(1);
});

it('does not mark reservations when expiring notices are disabled', function (): void {
    notif(['notify_reservation_expiring' => false]);
    Http::fake();
    $soon = newOrder(['reserved_until' => now()->addHours(3)], 'reservation');

    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();

    Http::assertNothingSent();
    expect($soon->fresh()->expiring_notified_at)->toBeNull();
});

it('masks personal data (NF-15)', function (): void {
    expect(PiiMask::phone('+79001234567'))->toBe('+7******4567')
        ->and(PiiMask::phone('8 (900) 123-45-67'))->toBe('*******4567')
        ->and(PiiMask::phone(null))->toBe('')
        ->and(PiiMask::email('ivan@example.ru'))->toBe('i***@example.ru')
        ->and(PiiMask::email('bad'))->toBe('b***')
        ->and(PiiMask::email(null))->toBe('');
});

it('renders the mails with shop name, items and deadline', function (): void {
    $order = newOrder(['reserved_until' => now()->addDay()], 'reservation');

    $html = (new OrderPlacedCustomerMail($order))->render();

    expect($html)->toContain($order->number)->toContain('Чайник')->toContain('Резерв действует до')
        ->and((new NewRequestShopMail($order))->render())->toContain('/orders/'.$order->uuid);
});
