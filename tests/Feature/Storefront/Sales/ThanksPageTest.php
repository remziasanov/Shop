<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
});

it('shows the order by its UUID with items, totals, delivery and the thank-you text, not indexed (SF-47)', function () {
    sales(['thanks_order_text' => 'Спасибо, ждите звонка!']);
    $order = Order::factory()->create(['delivery_method' => 'Курьерская доставка', 'delivery_address' => 'г. Тест, ул. 1', 'delivery_price' => '300.00', 'items_total' => '1000.00', 'total' => '1300.00']);
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Лампа Сфера', 'qty' => 2, 'price' => '500.00', 'total' => '1000.00']);

    $this->get(route('order.thanks', $order->uuid))->assertOk()
        ->assertSee($order->number)
        ->assertSee('Лампа Сфера')
        ->assertSee('Курьерская доставка')
        ->assertSee('г. Тест, ул. 1')
        ->assertSeeText("1\u{202F}300\u{00A0}₽")
        ->assertSee('Спасибо, ждите звонка!')
        ->assertSee('noindex', false);
});

it('answers 404 for an unknown or malformed identifier', function () {
    $this->get(route('order.thanks', (string) Str::uuid()))->assertNotFound();
    $this->get('/order/12')->assertNotFound();
});
