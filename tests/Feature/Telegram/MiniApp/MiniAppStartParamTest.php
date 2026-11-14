<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Support/telegram_miniapp.php';

use App\Models\Category;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Models\TelegramAccount;
use App\Services\Telegram\MiniApp\TelegramStartParamResolver;
use App\Settings\TelegramSettings;
use App\Support\TelegramMode;
use Illuminate\Testing\TestResponse;

/**
 * TM-08: every launch parameter, the unsigned `?to=` hint with the same checks, the IDOR guard of o_{uuid} (CA-28).
 */
beforeEach(function (): void {
    $this->withoutVite();
    connectTelegramBot(['miniapp_enabled' => true]);
    $this->account = TelegramAccount::factory()->create(['telegram_user_id' => 777]);
    $this->withSession(tgSession($this->account));
});

function tgStart(string $param): TestResponse
{
    return test()->withSession([TelegramMode::START_KEY => $param])->get('/tg/start');
}

it('opens a product by id (p_{id})', function (): void {
    $product = sellable(['slug' => 'kurtka']);

    tgStart('p_'.$product->id)->assertRedirect(route('product.show', 'kurtka'));
});

it('opens a category by id (c_{id})', function (): void {
    $category = Category::factory()->create(['slug' => 'obuv']);

    tgStart('c_'.$category->id)->assertRedirect(route('catalog.category', 'obuv'));
});

it('opens the cart and the order history (cart, orders)', function (): void {
    tgStart('cart')->assertRedirect(route('cart'));
    tgStart('orders')->assertRedirect(route('customer.orders'));
});

it('says «Товар недоступен» for an unknown, inactive or hidden object (TM-08)', function (): void {
    $inactive = sellable(['is_active' => false]);

    foreach (['p_999999', 'p_'.$inactive->id, 'c_999999', 'nonsense', 'p_abc'] as $param) {
        tgStart($param)->assertRedirect(route('home'))->assertSessionHas(TelegramStartParamResolver::NOTICE_KEY, 'Товар недоступен');
    }

    $this->get('/')->assertOk()->assertSee('Товар недоступен');
});

it('opens an order only for the Telegram or the customer that owns it, else 404 (o_{uuid}, CA-28)', function (): void {
    $own = Order::factory()->create(['telegram_account_id' => $this->account->id]);
    $foreign = Order::factory()->create(['telegram_account_id' => TelegramAccount::factory()->create()->id]);
    $guest = Order::factory()->create();

    tgStart('o_'.$own->uuid)->assertRedirect(route('order.thanks', $own->uuid));
    tgStart('o_'.$foreign->uuid)->assertNotFound();
    tgStart('o_'.$guest->uuid)->assertNotFound();
    tgStart('o_'.fake()->uuid())->assertNotFound();

    $customer = makeCustomer();
    $mine = Order::factory()->create(['customer_id' => $customer->id]);
    signInCustomer($this, $customer);

    tgStart('o_'.$mine->uuid)->assertRedirect(route('customer.orders.show', $mine->uuid));
});

it('applies the unsigned ?to= hint with the same ownership check (TM-08)', function (): void {
    $foreign = Order::factory()->create(['telegram_account_id' => TelegramAccount::factory()->create()->id]);

    $this->withSession([TelegramMode::HINT_KEY => 'o_'.$foreign->uuid])->get('/tg/start')->assertNotFound();
});

it('remembers a promo code and opens the home page (promo_{code})', function (): void {
    $code = PromoCode::factory()->create(['code' => 'SPRING10']);

    tgStart('promo_spring10')
        ->assertRedirect(route('home'))
        ->assertCookie('shop_promo', 'SPRING10');

    tgStart('promo_NOSUCH')->assertRedirect(route('home'))->assertSessionHas(TelegramStartParamResolver::NOTICE_KEY, 'Промокод недоступен');
    expect($code->code)->toBe('SPRING10');
});

it('follows a promo link with attribution (go_{slug}, PM-21…PM-24)', function (): void {
    $product = sellable(['slug' => 'kurtka']);
    $link = PromoLink::factory()->toProduct($product)->create(['slug' => 'spring-ad']);

    tgStart('go_spring-ad')
        ->assertRedirect(url('/product/kurtka'))
        ->assertCookie('shop_pl', (string) $link->id);

    tgStart('go_missing')->assertRedirect(route('home'))->assertSessionHas(TelegramStartParamResolver::NOTICE_KEY, 'Товар недоступен');

    PromoLink::factory()->toProduct($product)->create(['slug' => 'old-ad', 'is_active' => false]);
    tgStart('go_old-ad')->assertRedirect(route('catalog.index'))->assertSessionHas('promo_link_notice');
});

it('marks the links as verified (check, TG-03)', function (): void {
    expect(app(TelegramSettings::class)->startapp_verified_at)->toBeNull();

    tgStart('check')->assertRedirect(route('home'));

    app()->forgetInstance(TelegramSettings::class);
    expect(app(TelegramSettings::class)->startapp_verified_at)->not->toBeNull();
});

it('sends staff parameters to the home page until the workplace exists (staff, staff_o_{id})', function (): void {
    tgStart('staff')->assertRedirect(route('home'));
    tgStart('staff_o_15')->assertRedirect(route('home'));
});

it('opens the launch page once and only in the Telegram mode (TM-08)', function (): void {
    tgStart('cart')->assertRedirect(route('cart'));
    $this->get('/tg/start')->assertRedirect(route('home'));
});

it('ignores /tg/start without a Telegram session', function (): void {
    $this->flushSession();
    $product = sellable();

    $this->withSession([TelegramMode::START_KEY => 'p_'.$product->id])->get('/tg/start')->assertRedirect(route('home'));
});
