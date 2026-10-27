<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Jobs\SendMailNotification;
use App\Jobs\SendOrderNotifications;
use App\Jobs\SendTelegramNotification;
use App\Mail\NewRequestShopMail;
use App\Mail\OrderPlacedCustomerMail;
use App\Mail\OrderStatusChangedCustomerMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Notifications\MailDeliveryException;
use App\Services\Notifications\Messages\NewRequestMessage;
use App\Services\Notifications\Messages\OrderMessage;
use App\Services\Notifications\TelegramNotifier;
use App\Services\Orders\OrderService;
use App\Settings\NotificationSettings;
use App\Support\MarkdownEscaper;
use App\Support\PiiMask;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;

/**
 * @return array{html: string, text: string}
 */
function sentBodies(object $mailable): array
{
    Mail::mailer('array')->to('someone@example.com')->send($mailable);
    $message = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    return ['html' => (string) $message->getHtmlBody(), 'text' => (string) $message->getTextBody()];
}

function hostileOrder(): Order
{
    $order = newOrder([
        'customer_name' => 'Ivan [Pay here](https://evil.example/pay)',
        'customer_email' => 'buyer@example.com',
        'customer_comment' => "# Header\n```code```\n<script>alert(1)</script>\n[Login](https://evil.example/login)\n\n    indented",
        'delivery_method' => 'Courier *bold*',
        'delivery_address' => 'Street *1*, [x](https://evil.example/a)',
    ]);
    $order->items()->first()->update(['name' => '[Buy](https://evil.example/item) | # Item']);

    return $order->fresh();
}

dataset('customer text mails', [
    'new request to the shop' => [fn (Order $o) => new NewRequestShopMail($o)],
    'order placed' => [fn (Order $o) => new OrderPlacedCustomerMail($o)],
    'status changed' => [fn (Order $o) => new OrderStatusChangedCustomerMail($o, OrderStatus::Confirmed)],
]);

it('keeps customer text inert in HTML and text mails (NF-10)', function (Closure $make): void {
    notif();
    $order = hostileOrder();
    $bodies = sentBodies($make($order));

    $html = $bodies['html'];
    // The only links are the shop's own (logo, admin button, footer); none points to the attacker.
    expect($html)->not->toContain('href="https://evil.example')
        ->and($html)->not->toContain('<script')
        ->and($html)->not->toContain('<h1>Header')
        ->and($html)->not->toContain('<pre><code>indented')
        ->and($html)->not->toContain('<code>code</code>')
        ->and($html)->not->toContain('<strong>bold</strong>')
        ->and($html)->not->toContain('<em>1</em>');
    // The text is still visible to the reader.
    expect($bodies['text'])->toContain('Ivan');
})->with('customer text mails');

it('shows the hostile name as text in the heading of the order mail', function (): void {
    notif();
    $html = sentBodies(new OrderPlacedCustomerMail(hostileOrder()))['html'];

    expect($html)->toContain('Ivan [Pay here](https://evil.example/pay)')
        ->and($html)->not->toContain('<a href="https://evil.example');
});

function telegramOrder(int $items): Order
{
    $order = newOrder(['customer_name' => 'A & B "Co"']);
    $order->items()->delete();

    for ($i = 0; $i < $items; $i++) {
        OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Кабель "HDMI" & <адаптер> для телевизора, длина 2 м — '.$i, 'qty' => 1, 'price' => '1.00', 'total' => '1.00']);
    }

    return $order->fresh();
}

it('builds a long Telegram message within the limit and keeps total and admin link (NT-02)', function (): void {
    notif();
    $order = telegramOrder(60);
    $html = (new NewRequestMessage)->render($order);

    expect(OrderMessage::visibleLength($html))->toBeLessThanOrEqual(4096)
        ->and($html)->toContain('<a href="'.OrderMessage::adminUrl($order).'">')
        ->and($html)->toContain('и ещё')
        ->and($html)->toContain('<b>');
});

it('builds valid Telegram HTML: balanced tags and no broken entities', function (): void {
    notif();
    $html = (new NewRequestMessage)->render(telegramOrder(100));

    foreach (['b', 'a'] as $tag) {
        expect(substr_count($html, '<'.$tag.'>') + substr_count($html, '<'.$tag.' '))->toBe(substr_count($html, '</'.$tag.'>'));
    }

    // Every ampersand starts a complete entity.
    expect(preg_match_all('/&(?!(?:amp|lt|gt|quot|#039);)/', $html))->toBe(0)
        ->and(preg_match('/<[^>]*$/', $html))->toBe(0)
        ->and(preg_match('/&[a-z#0-9]*$/', $html))->toBe(0);
});

it('lists every item when they fit, without the remainder line', function (): void {
    notif();
    $html = (new NewRequestMessage)->render(telegramOrder(3));

    expect($html)->not->toContain('и ещё')
        ->and(substr_count($html, '• '))->toBe(3);
});

it('sends the long message to Telegram without cutting it', function (): void {
    notif();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $order = telegramOrder(100);

    app(TelegramNotifier::class)->send((new NewRequestMessage)->render($order));

    Http::assertSent(fn ($request): bool => str_contains($request['text'], OrderMessage::adminUrl($order)) && OrderMessage::visibleLength($request['text']) <= 4096);
});

it('falls back to escaped plain text when a foreign message is too long', function (): void {
    notif();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

    app(TelegramNotifier::class)->send('<b>'.str_repeat('a&amp;', 3000).'</b>');

    Http::assertSent(fn ($request): bool => OrderMessage::visibleLength($request['text']) <= 4096 && ! str_contains($request['text'], '<b>') && preg_match('/&(?!amp;)/', $request['text']) === 0);
});

it('rethrows mail transport errors without the recipient address (NF-15)', function (): void {
    notif();
    $order = newOrder(['customer_email' => 'secret.buyer@example.com']);
    Mail::shouldReceive('to')->andThrow(new TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 <secret.buyer@example.com> user unknown".'));

    try {
        (new SendMailNotification($order->id, SendMailNotification::CUSTOMER_PLACED))->handle();
        $this->fail('Expected an exception.');
    } catch (MailDeliveryException $e) {
        expect($e->getMessage())->not->toContain('secret.buyer@example.com')
            ->and($e->getMessage())->toContain('s***@example.com')
            ->and($e->getPrevious())->toBeNull();
    }
});

it('keeps non-transport errors (a broken template) as they are', function (): void {
    notif();
    $order = newOrder(['customer_email' => 'buyer@example.com']);
    Mail::shouldReceive('to')->andThrow(new LogicException('template broke'));

    expect(fn () => (new SendMailNotification($order->id, SendMailNotification::CUSTOMER_PLACED))->handle())
        ->toThrow(LogicException::class, 'template broke');
});

it('logs a failed mail job with masked data only', function (): void {
    Log::spy();

    (new SendMailNotification(1, SendMailNotification::SHOP_NEW, 'owner@example.com'))->failed(new RuntimeException('550 <owner@example.com> rejected'));

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['to'] === 'o***@example.com' && ! str_contains(json_encode($context, JSON_UNESCAPED_UNICODE), 'owner@example.com'))->once();
});

it('scrubs emails inside free text', function (): void {
    expect(PiiMask::scrubEmails('RCPT <ivan@example.ru>, "x.y@mail.example.com". Bye'))->toBe('RCPT <i***@example.ru>, "x***@mail.example.com". Bye');
});

it('makes the fan-out all-or-nothing and idempotent on retry (NT-01)', function (): void {
    config(['queue.default' => 'database']);
    notif(['notify_emails' => ['a@example.com', 'b@example.com']]);
    $order = newOrder();
    $fail = true;
    Event::listen(JobQueueing::class, function () use (&$fail): void {
        static $calls = 0;

        if ($fail && ++$calls === 2) {
            throw new RuntimeException('db write failed');
        }
    });

    $job = new SendOrderNotifications($order->id, SendOrderNotifications::EVENT_CREATED);

    expect(fn () => $job->handle(app(NotificationSettings::class), app(TelegramNotifier::class)))->toThrow(RuntimeException::class, 'db write failed')
        ->and(DB::table('jobs')->count())->toBe(0);

    // The retry queues every job exactly once: customer mail, two shop mails, Telegram.
    $fail = false;
    $job->handle(app(NotificationSettings::class), app(TelegramNotifier::class));
    expect(DB::table('jobs')->count())->toBe(4);

    // A repeated fan-out (parent retried after success) adds nothing.
    $job->handle(app(NotificationSettings::class), app(TelegramNotifier::class));
    expect(DB::table('jobs')->count())->toBe(4);
});

it('rolls back the expiring claim when queueing fails and retries on the next run', function (): void {
    config(['queue.default' => 'database']);
    notif();
    $order = Order::factory()->reservation()->reservedUntil(now()->addHours(2))->create();
    $fail = true;
    Event::listen(JobQueueing::class, function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('db write failed');
        }
    });
    Exceptions::fake();

    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();

    expect($order->fresh()->expiring_notified_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);

    $fail = false;
    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();
    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();

    expect($order->fresh()->expiring_notified_at)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('makes child jobs unique per order, channel, recipient and event', function (): void {
    expect((new SendMailNotification(5, SendMailNotification::SHOP_NEW, 'a@example.com'))->uniqueId())
        ->not->toBe((new SendMailNotification(5, SendMailNotification::SHOP_NEW, 'b@example.com'))->uniqueId())
        ->and((new SendMailNotification(5, SendMailNotification::CUSTOMER_STATUS, null, 'ready'))->uniqueId())
        ->not->toBe((new SendMailNotification(5, SendMailNotification::CUSTOMER_STATUS, null, 'cancelled'))->uniqueId())
        ->and((new SendTelegramNotification(5, SendTelegramNotification::EXPIRING))->uniqueId())
        ->not->toBe((new SendTelegramNotification(5, SendTelegramNotification::EXPIRED))->uniqueId())
        ->and((new SendTelegramNotification(5, SendTelegramNotification::EXPIRING))->uniqueFor)->toBe(3600);
});

it('treats an expiry as a system action only when the event says so', function (): void {
    notif();
    Queue::fake();
    $order = newOrder([], 'reservation');

    OrderStatusChanged::dispatch($order, OrderStatus::Reserved, OrderStatus::Expired, null, false);
    Queue::assertNothingPushed();

    OrderStatusChanged::dispatch($order, OrderStatus::Reserved, OrderStatus::Expired, null, true);
    Queue::assertPushed(SendOrderNotifications::class, fn (SendOrderNotifications $j): bool => $j->event === SendOrderNotifications::EVENT_EXPIRED);
});

it('flags the expiry by the order service as made by the system', function (): void {
    Event::fake([OrderStatusChanged::class]);
    $order = Order::factory()->reservation()->reservedUntil(now()->subHour())->create();

    app(OrderService::class)->expireReservations();

    Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e): bool => $e->bySystem === true && $e->user === null && $e->order->is($order));
});

it('shows customer text without markdown backslashes in the plain-text part', function (): void {
    notif();
    $order = newOrder(['customer_name' => 'Ivan (the *best*)', 'customer_phone' => '+7 (999) 123-45-67']);

    $bodies = sentBodies(new OrderPlacedCustomerMail($order->fresh()));

    expect($bodies['text'])->toContain('Ivan (the *best*)')
        ->and($bodies['text'])->not->toContain('\\(')
        ->and($bodies['html'])->toContain('Ivan (the *best*)')
        ->and(MarkdownEscaper::unescape(MarkdownEscaper::inline('a\\*b [x](y) _z_ \\')))->toBe('a\\*b [x](y) _z_ \\');
});

it('counts Telegram message length in UTF-16 code units', function (): void {
    expect(OrderMessage::visibleLength('<b>ab</b>'))->toBe(2)
        ->and(OrderMessage::visibleLength('😀'))->toBe(2)
        ->and(OrderMessage::cutUtf16('a😀b', 2))->toBe('a')
        ->and(OrderMessage::cutUtf16('a😀b', 3))->toBe('a😀');
});
