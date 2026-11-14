<?php

declare(strict_types=1);

use App\Enums\OrderChannel;
use App\Enums\SalesMode;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Livewire\Storefront\CheckoutForm;
use App\Livewire\Storefront\InquiryForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\InquiryData;
use App\Services\Orders\OrderChannelResolver;
use App\Services\Orders\OrderService;
use App\Support\TelegramMode;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0]);
});

function pwaContacts($component)
{
    foreach (['name' => 'Иван Тестов', 'phone' => '8 900 123-45-67', 'email' => '', 'comment' => '', 'consent' => true] as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

function submitCheckout(?string $channel): Order
{
    $cart = cartWith(sellable(['sales_mode' => SalesMode::Cart]));
    $component = livewireWithCart(CheckoutForm::class, $cart);
    test()->travel(5)->seconds();

    if ($channel !== null) {
        $component->set('channel', $channel);
    }

    fillCheckout($component)->call('submit')->assertHasNoErrors();

    return Order::query()->latest('id')->firstOrFail();
}

it('stores web by default and for an unknown value (TM-14)', function (?string $sent) {
    expect(submitCheckout($sent)->channel)->toBe(OrderChannel::Web);
})->with([null, 'web', 'desktop', '', 'PWA']);

it('stores pwa when the installed app sends it (MB-23)', function () {
    expect(submitCheckout('pwa')->channel)->toBe(OrderChannel::Pwa);
});

it('never accepts the telegram channel from the client (TM-14)', function () {
    expect(submitCheckout('telegram')->channel)->toBe(OrderChannel::Web);
});

it('resolves telegram only from the server session flag and ignores the client there (MB-23, TM-14)', function () {
    $resolver = app(OrderChannelResolver::class);

    expect($resolver->resolve('pwa'))->toBe(OrderChannel::Pwa)
        ->and($resolver->resolve('telegram'))->toBe(OrderChannel::Web)
        ->and($resolver->resolve(null))->toBe(OrderChannel::Web);

    session()->put(TelegramMode::SESSION_KEY, true);

    expect(TelegramMode::active())->toBeTrue()
        ->and($resolver->resolve('pwa'))->toBe(OrderChannel::Telegram)
        ->and($resolver->resolve('web'))->toBe(OrderChannel::Telegram);
});

it('stores the channel of a reservation and of an inquiry (TM-14)', function () {
    $product = sellable(['sales_mode' => SalesMode::Both, 'stock_qty' => 5]);
    $inquiryProduct = sellable(['sales_mode' => SalesMode::Inquiry]);

    $reservation = livewireWithCart(ReservationForm::class, null, ['productId' => $product->id]);
    $this->travel(5)->seconds();
    pwaContacts($reservation)->set('qty', 1)->set('channel', 'pwa')->call('submit')->assertHasNoErrors();

    $inquiry = livewireWithCart(InquiryForm::class, null, ['productId' => $inquiryProduct->id]);
    $this->travel(5)->seconds();
    pwaContacts($inquiry)->set('qty', 1)->set('channel', 'telegram')->call('submit')->assertHasNoErrors();

    $orders = Order::query()->orderBy('id')->get();

    expect($orders->pluck('channel')->all())->toBe([OrderChannel::Pwa, OrderChannel::Web]);
});

it('defaults the service data classes to web and stores an explicit channel (TM-14)', function () {
    expect(checkoutData()->channel)->toBe(OrderChannel::Web)
        ->and(reservationData()->channel)->toBe(OrderChannel::Web)
        ->and(inquiryData()->channel)->toBe(OrderChannel::Web);

    $product = sellable(['sales_mode' => SalesMode::Inquiry]);
    $data = new InquiryData(1, 'Иван Тестов', '+7 900 123-45-67', null, null, true, '127.0.0.1', 'Pest', channel: OrderChannel::Telegram);

    // The service trusts the typed enum (only the resolver sees raw client input).
    expect(app(OrderService::class)->createInquiry($product, $data)->channel)->toBe(OrderChannel::Telegram);
});

it('renders the hidden channel field in the three sales forms (MB-23)', function () {
    $reservable = sellable(['sales_mode' => SalesMode::Both, 'stock_qty' => 5]);
    $inquirable = sellable(['sales_mode' => SalesMode::Inquiry]);

    Livewire::test(ReservationForm::class, ['productId' => $reservable->id])->assertSeeHtml('data-channel-field');
    Livewire::test(InquiryForm::class, ['productId' => $inquirable->id])->assertSeeHtml('data-channel-field');
    livewireWithCart(CheckoutForm::class, cartWith(sellable(['sales_mode' => SalesMode::Cart])))->assertSeeHtml('data-channel-field');
});

it('backfills existing requests with web through the column default (TM-14)', function () {
    $order = Order::factory()->create();

    expect($order->fresh()->channel)->toBe(OrderChannel::Web);
});

describe('admin', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->admin()->create());
    });

    it('shows the channel badge and filters the list by channel (TM-14)', function () {
        $web = Order::factory()->create();
        $pwa = Order::factory()->channel(OrderChannel::Pwa)->create();
        $telegram = Order::factory()->channel(OrderChannel::Telegram)->create();

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$web, $pwa, $telegram])
            ->assertTableColumnStateSet('channel', OrderChannel::Pwa, $pwa)
            ->assertSee('Приложение')
            ->filterTable('channel', 'pwa')
            ->assertCanSeeTableRecords([$pwa])
            ->assertCanNotSeeTableRecords([$web, $telegram])
            ->resetTableFilters()
            ->filterTable('channel', 'telegram')
            ->assertCanSeeTableRecords([$telegram])
            ->assertCanNotSeeTableRecords([$web, $pwa]);
    });

    it('shows the channel on the request card with a tel: link to the customer (TM-14, MB-14)', function () {
        $order = Order::factory()->channel(OrderChannel::Telegram)->create(['customer_phone' => '+79001234567']);

        $this->get(OrderResource::getUrl('view', ['record' => $order]))
            ->assertOk()
            ->assertSee('Канал')
            ->assertSee('Telegram')
            ->assertSee('tel:+79001234567', false);
    });
});
