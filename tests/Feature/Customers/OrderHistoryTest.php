<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Policies\OrderPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * CA-11, CA-28 (TZ 17.3): the order history of the cabinet and the detail page by UUID; another customer's UUID is a 404.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->customer = makeCustomer();
    signInCustomer($this, $this->customer);
});

function ownOrder(array $attributes = []): Order
{
    return Order::factory()->create(array_merge(['customer_id' => test()->customer->id], $attributes));
}

it('is closed to guests', function (): void {
    session()->flush();
    nextRequest();

    $order = ownOrder();

    $this->get(route('customer.orders'))->assertRedirect(route('customer.login'));
    $this->get(route('customer.orders.show', $order->uuid))->assertRedirect(route('customer.login'));
});

it('lists requests of all types of the customer only (CA-11, CA-28)', function (): void {
    ownOrder(['number' => 'Z-10001']);
    ownOrder(['number' => 'R-10002'])->forceFill(['type' => 'reservation'])->save();
    ownOrder(['number' => 'Q-10003'])->forceFill(['type' => 'inquiry'])->save();
    Order::factory()->create(['number' => 'Z-99999', 'customer_id' => makeCustomer()->id]);
    Order::factory()->create(['number' => 'Z-88888']);

    $this->get(route('customer.orders'))
        ->assertOk()
        ->assertSee('Z-10001')->assertSee('R-10002')->assertSee('Q-10003')
        ->assertDontSee('Z-99999')->assertDontSee('Z-88888')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('filters by type, status and period and sorts by date (CA-11)', function (): void {
    ownOrder(['number' => 'Z-20001', 'created_at' => now()->subDays(10)]);
    ownOrder(['number' => 'Z-20002', 'created_at' => now()->subDays(1), 'status' => OrderStatus::Completed]);
    ownOrder(['number' => 'Q-20003', 'created_at' => now()->subDays(2)])->forceFill(['type' => 'inquiry'])->save();

    $this->get(route('customer.orders', ['type' => 'inquiry']))->assertSee('Q-20003')->assertDontSee('Z-20001')->assertDontSee('Z-20002');
    $this->get(route('customer.orders', ['status' => 'completed']))->assertSee('Z-20002')->assertDontSee('Z-20001');
    $this->get(route('customer.orders', ['from' => now()->subDays(3)->toDateString()]))->assertSee('Z-20002')->assertSee('Q-20003')->assertDontSee('Z-20001');
    $this->get(route('customer.orders', ['until' => now()->subDays(5)->toDateString()]))->assertSee('Z-20001')->assertDontSee('Z-20002');

    $this->get(route('customer.orders', ['sort' => 'asc']))->assertSeeInOrder(['Z-20001', 'Q-20003', 'Z-20002']);
    $this->get(route('customer.orders'))->assertSeeInOrder(['Z-20002', 'Q-20003', 'Z-20001']);
});

it('paginates by 20', function (): void {
    foreach (range(1, 25) as $i) {
        ownOrder(['number' => 'Z-3'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'created_at' => now()->subMinutes(100 - $i)]);
    }

    $first = $this->get(route('customer.orders'))->assertOk();
    $first->assertSee('Z-30025')->assertDontSee('Z-30001');

    $this->get(route('customer.orders', ['page' => 2]))->assertOk()->assertSee('Z-30001')->assertDontSee('Z-30025');
});

it('shows the detail with the price snapshot, the group and the status history without staff comments (CA-11)', function (): void {
    $product = sellable(['name' => 'Тестовый товар']);
    $order = ownOrder([
        'customer_group_name' => 'Розница',
        'delivery_method' => 'Курьер',
        'payment_method' => 'Наличными',
        'delivery_price' => '300.00',
        'items_total' => '2000.00',
        'total' => '2300.00',
        'manager_comment' => 'Секретная заметка менеджера',
    ]);
    OrderItem::factory()->forProduct($product, 2)->create(['order_id' => $order->id, 'price' => '1000.00', 'total' => '2000.00']);
    // The catalog price changes later: the order keeps its snapshot.
    $product->forceFill(['base_price' => '5000.00'])->save();
    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'to_status' => OrderStatus::New, 'comment' => 'Внутренний комментарий']);
    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'from_status' => OrderStatus::New, 'to_status' => OrderStatus::Confirmed, 'comment' => 'Позвонить в пятницу']);

    $this->get(route('customer.orders.show', $order->uuid))
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee('Тестовый товар')
        ->assertSee('Розница')
        ->assertSee('Курьер')
        ->assertSee('data-total', false)
        ->assertSeeInOrder(['Новый', 'Подтверждён'])
        ->assertDontSee('Секретная заметка менеджера')
        ->assertDontSee('Внутренний комментарий')
        ->assertDontSee('Позвонить в пятницу')
        ->assertDontSee('5 000');
});

it('shows the reservation term on a reservation', function (): void {
    $order = ownOrder(['reserved_until' => now()->addHours(30)->startOfMinute()])->forceFill(['type' => 'reservation']);
    $order->save();

    $this->get(route('customer.orders.show', $order->uuid))->assertOk()->assertSee('data-reserved-until', false)->assertSee($order->reserved_until->format('d.m.Y H:i'));
});

it('answers 404, not 403, for the UUID of another customer, a guest request or garbage (CA-28)', function (): void {
    $foreign = Order::factory()->create(['customer_id' => makeCustomer()->id]);
    $guest = Order::factory()->create(['customer_id' => null]);

    $this->get(route('customer.orders.show', $foreign->uuid))->assertNotFound();
    $this->get(route('customer.orders.show', $guest->uuid))->assertNotFound();
    $this->get(route('customer.orders.show', 'not-a-uuid'))->assertNotFound();
    $this->get(route('customer.orders.show', (string) Str::uuid()))->assertNotFound();
    $this->post(route('customer.orders.repeat', $foreign->uuid))->assertNotFound();
});

it('has a policy that opens only own requests and nothing to staff by permission (CA-28)', function (): void {
    $own = ownOrder();
    $foreign = Order::factory()->create(['customer_id' => makeCustomer()->id]);
    $other = makeCustomer();

    expect(Gate::forUser($this->customer)->allows('viewAsCustomer', $own))->toBeTrue()
        ->and(Gate::forUser($this->customer)->allows('viewAsCustomer', $foreign))->toBeFalse()
        ->and(Gate::forUser($other)->allows('viewAsCustomer', $own))->toBeFalse()
        ->and((new OrderPolicy)->viewAsCustomer($this->customer, Order::factory()->create(['customer_id' => null])))->toBeFalse();
});
