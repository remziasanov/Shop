<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_miniapp.php';
require_once __DIR__.'/../../Support/telegram_staff.php';

use App\Enums\AuditAction;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\SalesMode;
use App\Enums\StatusChangeSource;
use App\Enums\TelegramMessageKind;
use App\Enums\VerificationPurpose;
use App\Filament\Pages\MyTelegram;
use App\Filament\Pages\Settings\ManageTelegramSettings;
use App\Filament\Resources\AuditLog\Pages\ListAuditLog;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Livewire\Staff\OrderCard;
use App\Livewire\Staff\WorkplaceList;
use App\Livewire\Storefront\CheckoutForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\AdminAuditEntry;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\TelegramAccount;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Catalog\CatalogVersion;
use App\Services\Orders\OrderService;
use App\Services\Telegram\MiniApp\MiniAppConfig;
use App\Services\Telegram\TelegramBotSetup;
use App\Settings\TelegramSettings;
use App\Support\TelegramMode;
use Filament\Actions\Testing\TestAction;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * TZ section 14, scenarios 22-28 (version 2.1). Telegram is reached only through `Http::fake()`: the webhook and
 * `/tg/session` get updates and `initData` signed with the fake test token, the outgoing Bot API calls are asserted.
 * What needs a phone or a real Telegram client is the manual checklist of docs/PROGRESS.md, stage 18.
 */
beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    RateLimiter::clear('login');
    Cache::flush();
    Mail::fake();
    config(['app.url' => 'https://shop.example.com']);
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0, 'reservation_hours' => 48]);
    staffBot(['autologin_enabled' => true]);
    fakeTelegramSend(77, -1001);
});

/** Every `sendMessage` of the test addressed to the chat, as payloads. */
function v21Sent(int|string $chatId): array
{
    return array_values(array_filter(telegramCalls()['sendMessage'] ?? [], fn (array $call): bool => (string) $call['chat_id'] === (string) $chatId));
}

/** The page text with all kinds of spaces dropped, so that «1 000 ₽» is comparable. */
function v21Plain(string $html): string
{
    return preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', html_entity_decode(strip_tags($html))) ?? '';
}

/** A signed `/tg/session` request of a Telegram user. */
function v21Launch(array $extra = [], int $id = 777, string $first = 'Иван', string $last = 'Петров', bool $writeAccess = true, string $token = TG_TEST_TOKEN): TestResponse
{
    $user = ['id' => $id, 'first_name' => $first, 'last_name' => $last, 'username' => 'tg'.$id, 'language_code' => 'ru'];

    if ($writeAccess) {
        $user['allows_write_to_pm'] = true;
    }

    return test()->postJson('/tg/session', ['initData' => tgInitData($extra, $user, token: $token)]);
}

/** The contact message of the «Поделиться номером» button (TG-09). */
function v21Contact(int $updateId, int $userId, string $phone): array
{
    $update = tgMessage($updateId, '', $userId, ['contact' => ['phone_number' => $phone, 'first_name' => 'Иван', 'user_id' => $userId]]);
    unset($update['message']['text']);
    // The same person as in the Mini App launch: Telegram sends the profile names with every update.
    $update['message']['from']['first_name'] = 'Иван';
    $update['message']['from']['last_name'] = 'Петров';

    return $update;
}

// ---------------------------------------------------------------------------------------------
// Scenario 22. Телефон
// ---------------------------------------------------------------------------------------------

it('22. phone: bottom bar, filters sheet, sticky buy bar, number keyboard and autofill, the order of the installed app has the channel «Приложение»', function (): void {
    $category = Category::factory()->create(['name' => 'Посуда', 'slug' => 'posuda']);
    $product = Product::factory()->inCategory($category)->create(['name' => 'Кружка', 'base_price' => '1290.00', 'stock_qty' => 10, 'sales_mode' => SalesMode::Cart]);

    // Navigation by the bottom bar: catalog, search, cart, account.
    $home = $this->get(route('home'))->assertOk()->getContent();
    preg_match('#<nav[^>]*data-bottom-nav.*?</nav>#s', $home, $bar);

    expect($bar[0] ?? '')->toContain('data-bar-item="catalog"')->toContain('data-bar-item="search"')->toContain('data-bar-item="cart"')->toContain('data-bar-item="account"');

    // The filters open as a slide-over, the sorting as a bottom sheet.
    $listing = $this->get(route('catalog.category', $category->slug))->assertOk()->getContent();
    expect($listing)->toContain('data-open-filters')->toContain('data-open-sort')->toContain('id="sort-sheet"');

    // The card of the product: the sticky bar with the price and «В корзину».
    $page = $this->get(route('product.show', $product->slug))->assertOk()->getContent();
    expect($page)->toContain('data-sticky-buy')->toContain('data-sticky-price')->toContain('data-sticky-action="buy"')->toContain('В корзину');

    // The checkout: the bottom bar gives way to the sticky submit bar, the contact fields help the keyboard and the autofill.
    $cart = cartWith($product);
    $checkout = $this->withCookie('cart', $cart->token)->get(route('checkout'))->assertOk()->getContent();

    expect($checkout)->not->toContain('data-bottom-nav')
        ->and($checkout)->toContain('data-sticky-checkout-bar')
        ->and($checkout)->toContain('data-sticky-submit')
        ->and($checkout)->toMatch('#id="co-name"[^>]*autocomplete="name"#')
        ->and($checkout)->toMatch('#id="co-phone"[^>]*type="tel"#')
        ->and($checkout)->toContain('inputmode="tel"')
        ->and($checkout)->toMatch('#type="tel"[^>]*autocomplete="tel"#')
        ->and($checkout)->toContain('data-channel-field');

    // The site installed on the home screen and opened as an app sends channel=pwa in the hidden field.
    $component = livewireWithCart(CheckoutForm::class, $cart);
    $this->travel(5)->seconds();
    fillCheckout($component)->set('channel', 'pwa')->call('submit')->assertHasNoErrors();

    expect(Order::query()->firstOrFail()->channel)->toBe(OrderChannel::Pwa);
});

// ---------------------------------------------------------------------------------------------
// Scenario 23. Подключение бота
// ---------------------------------------------------------------------------------------------

it('23. connect the bot: every step is green, commands and the «Магазин» button are set, then the startapp check marks the links verified', function (): void {
    // A fresh shop: nothing is connected yet, only the token is typed into the settings page.
    $settings = app(TelegramSettings::class);
    foreach (['bot_token', 'webhook_secret', 'bot_id', 'bot_username', 'connected_at'] as $field) {
        $settings->{$field} = null;
    }
    $settings->miniapp_enabled = true;
    $settings->save();
    app()->forgetInstance(TelegramSettings::class);

    $token = '888:NEW-bot-token_xyz';
    Http::swap(new Factory);
    Http::fake([
        'api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 888, 'is_bot' => true, 'username' => 'new_shop_bot']]),
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => true]),
    ]);

    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    $component = Livewire::test(ManageTelegramSettings::class)
        ->fillForm(['bot_token' => $token])
        ->callAction(TestAction::make('connectBot')->schemaComponent('connectionActions', schema: 'form'));

    $setup = app(TelegramBotSetup::class);
    expect($setup->isConnected())->toBeTrue()
        ->and($component->html())->toContain('data-telegram-steps')->not->toContain($token);

    foreach (['getMe', 'setWebhook', 'setMyCommands', 'setChatMenuButton'] as $method) {
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/'.$method));
    }

    $calls = telegramCalls();
    expect($calls['setWebhook'][0]['url'])->toBe('https://shop.example.com/telegram/webhook')
        ->and(array_column($calls['setMyCommands'][0]['commands'], 'command'))->toBe(['start', 'orders', 'help', 'stop'])
        ->and($calls['setChatMenuButton'][0]['menu_button'])->toBe(['type' => 'web_app', 'text' => 'Магазин', 'web_app' => ['url' => 'https://shop.example.com/tg']]);

    // In @BotFather the Main Mini App is enabled (manual); «Проверить ссылку» is the launch with start_param=check.
    expect($setup->startappVerified())->toBeFalse();

    nextRequest();
    $this->flushSession();
    v21Launch(['start_param' => 'check'], token: $token)->assertOk()->assertJson(['url' => '/tg/start']);
    $this->get('/tg/start')->assertRedirect(route('home'));

    app()->forgetInstance(TelegramSettings::class);
    expect(app(TelegramBotSetup::class)->startappVerified())->toBeTrue()
        ->and(app(TelegramSettings::class)->startapp_verified_at)->not->toBeNull();

    // The state block shows the check link and the verified mark.
    app()->forgetInstance(TelegramBotSetup::class);
    $this->actingAs($admin);
    Livewire::test(ManageTelegramSettings::class)
        ->assertSeeHtml('href="https://t.me/new_shop_bot?startapp=check"')
        ->assertSee(__('telegram.admin.startapp.verified'));
});

// ---------------------------------------------------------------------------------------------
// Scenario 24. Заказ в Mini App гостем
// ---------------------------------------------------------------------------------------------

it('24. guest order in the Mini App: Telegram layout, cart, name and phone prefilled, channel telegram, work chat message with buttons, «принято» to the buyer', function (): void {
    $product = sellable(['name' => 'Чайник', 'base_price' => '1500.00', 'sales_mode' => SalesMode::Cart, 'stock_qty' => 5]);

    // «Магазин»: a signed initData opens the session of a brand new Telegram user.
    v21Launch()->assertOk()->assertJson(['url' => '/']);
    $account = TelegramAccount::query()->where('telegram_user_id', 777)->firstOrFail();

    expect($account->chat_started_at)->not->toBeNull()
        ->and(session(TelegramMode::SESSION_KEY))->toBeTrue();

    nextRequest();
    $this->get('/')->assertOk()
        ->assertSee('data-tg-topbar', false)
        ->assertSee('https://telegram.org/js/telegram-web-app.js', false)
        ->assertSee('data-tg-colors="shop"', false);

    // Add to cart: the cart belongs to the Telegram account, not only to a cookie.
    nextRequest();
    $this->postJson(route('cart.add'), ['product_id' => $product->id, 'qty' => 2])->assertOk();
    expect(Cart::query()->firstOrFail()->telegram_account_id)->toBe($account->id);

    // The checkout page has the main button for the Telegram bottom button (the big button at the bottom).
    nextRequest();
    $this->get(route('checkout'))->assertOk()->assertSee('data-tg-checkout', false);

    // The phone arrives with the TG-09 button: the contact goes through the webhook, the page asks /tg/phone.
    telegramWebhook(v21Contact(30, 777, '79991234567'))->assertOk()->assertJsonPath('text', __('telegram.bot.contact_saved'));
    nextRequest();
    $this->getJson('/tg/phone')->assertOk()->assertJson(['phone' => '+79991234567']);

    nextRequest();
    $form = Livewire::test(CheckoutForm::class)->assertSet('name', 'Иван Петров')->assertSet('phone', '+79991234567');
    $this->travel(5)->seconds();
    $form->set('delivery', 'samovyvoz')->set('payment', 'nalicnymi-pri-polucenii')->set('consent', true)->call('submit')->assertHasNoErrors();

    $order = Order::query()->firstOrFail();
    expect($order->channel)->toBe(OrderChannel::Telegram)
        ->and($order->telegram_account_id)->toBe($account->id)
        ->and($order->customer_id)->toBeNull()
        ->and($order->customer_phone)->toBe('+79991234567');

    // The work chat: one message with the channel and the buttons of the new order.
    $work = v21Sent('-1001');
    expect($work)->toHaveCount(1)
        ->and($work[0]['text'])->toContain($order->number)->toContain('Канал: Telegram')
        ->and(collect($work[0]['reply_markup']['inline_keyboard'])->flatten(1)->pluck('text')->all())->toContain('Подтвердить', 'Отменить')
        ->and($work[0]['reply_markup']['inline_keyboard'][0][0]['callback_data'])->toBe('o:cf:'.$order->id.':'.$order->refresh()->version);

    // The buyer: «Обращение № … принято» in his chat, once.
    $buyer = v21Sent('777');
    expect($buyer)->toHaveCount(1)
        ->and($buyer[0]['text'])->toContain(__('telegram_notify.created.title', ['number' => $order->number]))
        ->and($buyer[0]['reply_markup']['inline_keyboard'][0][0]['web_app']['url'])->toBe('https://shop.example.com/tg?to=o_'.$order->uuid);

    // The stock was taken once.
    expect($product->refresh()->stock_qty)->toBe(3);
});

// ---------------------------------------------------------------------------------------------
// Scenario 25. Оптовик в Mini App
// ---------------------------------------------------------------------------------------------

it('25. wholesale in the Mini App: sign in by e-mail code, group prices and history, next launch without a code, blocked customer opens as a guest', function (): void {
    $opt = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $mug = sellable(['name' => 'Кружка', 'base_price' => '1200.00', 'sales_mode' => SalesMode::Cart, 'stock_qty' => 100]);
    gpTier($mug, $opt, 1, '1000.00');
    CatalogVersion::bump();

    $customer = makeCustomer(['email' => 'opt.buyer@firm.example', 'customer_group_id' => $opt->id]);
    $history = Order::factory()->order()->create(['customer_id' => $customer->id, 'number' => 'Z-25001']);

    // A guest of the Mini App sees the retail price.
    v21Launch()->assertOk();
    nextRequest();
    $guestPage = v21Plain($this->get(route('product.show', $mug->slug))->assertOk()->getContent());
    expect($guestPage)->toContain('1200')->not->toContain('1000₽');

    // «Войти в аккаунт сайта»: only the code sign-in is offered, the link is made after the code.
    nextRequest();
    $this->get(route('customer.login'))->assertOk()->assertSee('data-tg-login-note', false)->assertDontSee('Вход по паролю');
    $this->post(route('customer.login.send-code'), ['email' => 'opt.buyer@firm.example']);
    $this->post(route('customer.login.verify'), ['code' => lastSentCode(VerificationPurpose::Login)])->assertRedirect(route('account'));

    $account = TelegramAccount::query()->where('telegram_user_id', 777)->firstOrFail();
    expect($account->customer_id)->toBe($customer->id)
        ->and(session(TelegramMode::SESSION_KEY))->toBeTrue();

    // Wholesale prices and the history of orders.
    nextRequest();
    $optPage = v21Plain($this->get(route('product.show', $mug->slug))->assertOk()->getContent());
    expect($optPage)->toContain('1000')->not->toContain('1200');

    nextRequest();
    $this->get(route('customer.orders'))->assertOk()->assertSee('Z-25001');

    // Close and open the Mini App again (a new session): in without a code.
    nextRequest();
    $this->flushSession();
    $this->get(route('customer.orders'))->assertRedirect();
    nextRequest();
    v21Launch()->assertOk();
    expect(session(Auth::guard('customer')->getName()))->toBe($customer->id)
        ->and(session(TelegramMode::LOGIN_KEY))->toBeTrue();

    nextRequest();
    $this->get(route('customer.orders'))->assertOk()->assertSee('Z-25001');
    expect($history->id)->toBeInt();

    // The administrator blocks the customer: the next launch is a guest and the link is dropped.
    nextRequest();
    $this->flushSession();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ViewCustomer::class, ['record' => $customer->getRouteKey()])->callAction('block', ['reason' => 'Проверка']);
    expect($customer->refresh()->is_blocked)->toBeTrue();

    nextRequest();
    $this->flushSession();
    v21Launch()->assertOk();

    expect(session(Auth::guard('customer')->getName()))->toBeNull()
        ->and(session(TelegramMode::LOGIN_KEY))->toBeNull()
        ->and(session(TelegramMode::SESSION_KEY))->toBeTrue()
        ->and($account->refresh()->customer_id)->toBeNull();

    nextRequest();
    $this->get(route('customer.orders'))->assertRedirect();
});

// ---------------------------------------------------------------------------------------------
// Scenario 26. С сайта в Telegram
// ---------------------------------------------------------------------------------------------

it('26. site to Telegram: reservation, «Получать статус в Telegram», confirmation, 6-hour reminder, /stop, «Открыть в Telegram» on the product page', function (): void {
    $product = sellable(['name' => 'Ваза', 'sales_mode' => SalesMode::Reservation, 'stock_qty' => 5]);

    // The guest reserves on the site.
    $form = Livewire::test(ReservationForm::class, ['productId' => $product->id]);
    $this->travel(5)->seconds();
    foreach (['name' => 'Анна Гостева', 'phone' => '8 900 111-22-33', 'email' => '', 'comment' => '', 'consent' => true, 'qty' => 1] as $key => $value) {
        $form->set($key, $value);
    }
    $form->call('submit')->assertHasNoErrors();

    $reservation = Order::query()->firstOrFail();
    expect($reservation->type)->toBe(OrderType::Reservation)->and($reservation->channel)->toBe(OrderChannel::Web);

    // The thanks page offers «Получать статус в Telegram» with a one-time o_ link.
    $html = $this->get(route('order.thanks', $reservation->uuid))->assertOk()->assertSee('data-telegram-follow', false)->getContent();
    expect($html)->toMatch('#href="https://t\.me/test_shop_bot\?start=o_[A-Za-z0-9_-]+"#');
    preg_match('#href="(https://t\.me/test_shop_bot\?start=(o_[A-Za-z0-9_-]+))"#', $html, $match);

    // The guest taps it: the bot binds the request and answers with the number and the status.
    $bind = telegramWebhook(tgMessage(40, '/start '.$match[2], 888))->assertOk();
    $text = (string) $bind->json('text');

    expect($text)->toContain(__('telegram.link.orders_followed'))->toContain($reservation->number)->toContain($reservation->status->label($reservation->type));

    $account = TelegramAccount::query()->where('telegram_user_id', 888)->firstOrFail();
    expect($reservation->refresh()->telegram_account_id)->toBe($account->id)->and($account->chat_started_at)->not->toBeNull();

    // The manager confirms the reservation in the admin: «Резерв подтверждён» comes to the chat.
    $manager = User::factory()->admin()->create();
    $this->actingAs($manager);
    Livewire::test(ViewOrder::class, ['record' => $reservation->getRouteKey()])->callAction('change_status', ['status' => OrderStatus::Reserved->value]);
    nextRequest();

    expect($reservation->refresh()->status)->toBe(OrderStatus::Reserved);
    $sent = v21Sent('888');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['text'])->toContain($reservation->number)->toContain(OrderStatus::Reserved->label(OrderType::Reservation));

    // Six hours before the end: one reminder (a second run of the scheduled command sends nothing more).
    $registered = collect(app(Schedule::class)->events())->contains(fn ($event): bool => str_contains((string) $event->command, 'shop:notify-expiring-reservations'));
    expect($registered)->toBeTrue();

    $this->travelTo($reservation->reserved_until->copy()->subHours(5));
    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();
    $this->artisan('shop:notify-expiring-reservations')->assertSuccessful();

    $sent = v21Sent('888');
    expect($sent)->toHaveCount(2)
        ->and($sent[1]['text'])->toContain(__('telegram_notify.expiring.title', ['number' => $reservation->number]))
        ->and(TelegramMessage::query()->where('kind', TelegramMessageKind::ReservationExpiring->value)->count())->toBe(1);

    // /stop: no further messages, the status changes of the manager stay silent.
    telegramWebhook(tgMessage(41, '/stop', 888))->assertJsonPath('text', __('telegram.bot.stopped'));
    expect($account->refresh()->notify_enabled)->toBeFalse();

    app(OrderService::class)->changeStatus($reservation->refresh(), OrderStatus::Cancelled, $manager);
    expect($reservation->refresh()->status)->toBe(OrderStatus::Cancelled)->and(v21Sent('888'))->toHaveCount(2);

    // «Открыть в Telegram» on the product page: only with verified startapp links, for phones, to the same product.
    $this->travelBack();
    $this->get(route('product.show', $product->slug))->assertOk()->assertDontSee('data-open-in-telegram', false);

    staffBot(['startapp_verified_at' => now()->toIso8601String()]);
    app(MiniAppConfig::class)->rememberSiteLinkBase();

    $phone = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148')
        ->get(route('product.show', $product->slug))->assertOk()->getContent();

    expect($phone)->toContain('data-open-in-telegram')->toContain('href="https://t.me/test_shop_bot?startapp=p_'.$product->id.'"');
});

// ---------------------------------------------------------------------------------------------
// Scenario 27. Менеджер в рабочем чате
// ---------------------------------------------------------------------------------------------

it('27. manager in the work chat: confirm redraws the message and tells the buyer, cancel needs two taps and returns the stock, no right means refusal, an old button changes nothing', function (): void {
    [$manager] = linkedStaff(['orders.view', 'orders.update'], 555, 'Иванов');
    $viewer = linkedStaff(['orders.view'], 556, 'Смотрящий')[0];
    $buyer = TelegramAccount::factory()->chatStarted()->create(['telegram_user_id' => 777]);
    $product = sellable(['stock_qty' => 10]);

    $make = function () use ($buyer, $product): Order {
        $cart = cartWith($product);
        $order = app(OrderService::class)->createFromCart($cart, checkoutData());
        $order->forceFill(['telegram_account_id' => $buyer->id])->save();

        return $order->refresh();
    };

    $order = $make();
    $first = collect(v21Sent('-1001'))->last();
    $confirm = $first['reply_markup']['inline_keyboard'][0][0]['callback_data'];
    expect($confirm)->toBe('o:cf:'.$order->id.':'.$order->version);

    // Without the right: a refusal and nothing changes.
    telegramWebhook(pressStaffButton(1, $confirm, 556))->assertJsonPath('text', 'Недостаточно прав для этого действия');
    expect($order->refresh()->status)->toBe(OrderStatus::New);

    // «Подтвердить»: status, redraw with the name and the time and the next buttons.
    telegramWebhook(pressStaffButton(2, $confirm))->assertJsonPath('method', 'answerCallbackQuery')->assertJsonPath('text', 'Подтверждено');

    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);
    $edit = telegramCalls()['editMessageText'][0];
    expect($edit['text'])->toContain('Статус: <b>Подтверждён</b>')->toContain('Подтвердил Иванов '.now()->format('H:i'))
        ->and(collect($edit['reply_markup']['inline_keyboard'][0])->pluck('text')->all())->toBe(['Готов к выдаче', 'Отменить']);

    // The buyer got exactly one status message.
    $toBuyer = v21Sent('777');
    expect($toBuyer)->toHaveCount(1)->and($toBuyer[0]['text'])->toContain($order->number)->toContain(OrderStatus::Confirmed->label($order->type));

    // The old button once more: «Обращение уже изменено», no second history row, no second message.
    $rows = OrderStatusHistory::query()->where('order_id', $order->id)->count();
    telegramWebhook(pressStaffButton(3, $confirm))->assertJsonPath('text', 'Обращение уже изменено');
    expect(OrderStatusHistory::query()->where('order_id', $order->id)->count())->toBe($rows)
        ->and(v21Sent('777'))->toHaveCount(1)
        ->and($viewer->id)->toBeInt();

    // «Отменить»: the first tap only asks, the second one cancels and returns the stock.
    $second = $make();
    $stockBefore = $product->refresh()->stock_qty;
    $cancel = 'o:cx:'.$second->id.':'.$second->version;

    telegramWebhook(pressStaffButton(4, $cancel))->assertJsonPath('text', 'Нажмите ещё раз в течение 30 секунд, чтобы отменить');
    expect($second->refresh()->status)->toBe(OrderStatus::New);
    $asked = collect(telegramCalls()['editMessageText'])->last();
    expect(collect($asked['reply_markup']['inline_keyboard'][0])->pluck('text')->all())->toContain('Точно отменить?');

    telegramWebhook(pressStaffButton(5, $cancel))->assertJsonPath('text', 'Обращение отменено');
    expect($second->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->refresh()->stock_qty)->toBe($stockBefore + 1)
        ->and(OrderStatusHistory::query()->where('order_id', $second->id)->where('to_status', 'cancelled')->firstOrFail()->user_id)->toBe($manager->id);
});

// ---------------------------------------------------------------------------------------------
// Scenario 28. Рабочее место
// ---------------------------------------------------------------------------------------------

it('28. workplace: link Telegram, /orders button, signed staff start, tab of expiring reservations, phone search, extend by 24 h, call link, /admin refused, audit with source Telegram', function (): void {
    // The staff member links Telegram in «Мой Telegram»: the s_ link, the question and the confirmation in the bot.
    $user = User::factory()->withPermissions(['orders.view', 'orders.update'])->create(['name' => 'Иванов', 'email' => 'ivanov@example.com']);
    $this->actingAs($user);
    $page = Livewire::test(MyTelegram::class)->callAction('connect');
    $link = (string) $page->get('link');
    nextRequest();

    expect($link)->toStartWith('https://t.me/test_shop_bot?start=s_');
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    $question = telegramWebhook(tgMessage(50, '/start '.$query['start'], 555))->assertOk();
    expect($question->json('text'))->toBe(__('telegram.link.confirm_staff', ['name' => 'Иванов']));
    telegramWebhook(pressButton(51, $question->json('reply_markup.inline_keyboard.0.0.callback_data'), 555))->assertJsonPath('text', __('telegram.link.done_toast'));

    $account = TelegramAccount::query()->where('telegram_user_id', 555)->firstOrFail();
    expect($account->user_id)->toBe($user->id);

    // /orders: «Рабочее место» next to «Мои заказы».
    $orders = telegramWebhook(tgMessage(52, '/orders', 555))->assertOk();
    $buttons = collect($orders->json('reply_markup.inline_keyboard'))->flatten(1)->keyBy('text');
    expect($buttons)->toHaveKey(__('telegram.bot.workplace'))
        ->and($buttons[__('telegram.bot.workplace')]['web_app']['url'])->toBe('https://shop.example.com/tg?mode=staff');

    // The signed start_param opens the workplace and signs in to the staff guard only.
    $today = Order::factory()->reservation()->reservedUntil(now()->addMinutes(90)->startOfMinute())->create(['customer_name' => 'Анатолий Звонарёв', 'customer_phone' => '+79001112233']);
    $later = Order::factory()->reservation()->reservedUntil(now()->addDays(4))->create(['customer_name' => 'Борис Потом', 'customer_phone' => '+79004445566']);
    $untilBefore = $today->reserved_until->copy();

    $this->flushSession();
    v21Launch(['start_param' => 'staff'], 555, 'Ivan', '', false)->assertOk()->assertJson(['url' => '/tg/staff'])->assertSessionHas(TelegramMode::STAFF_KEY, true);
    expect(Auth::guard('telegram_staff')->id())->toBe($user->id)->and(Auth::guard('web')->check())->toBeFalse();

    nextRequest();
    $this->get('/tg/staff')->assertOk();

    // «Резервы: истекают сегодня» and the search by phone in any format.
    Livewire::test(WorkplaceList::class)
        ->call('setTab', 'expiring')->assertSee('Анатолий Звонарёв')->assertDontSee('Борис Потом')
        ->call('setTab', 'all')->set('search', '8 (900) 444-55-66')->assertSee('Борис Потом')->assertDontSee('Анатолий Звонарёв');

    // The card: the call link, the extension by 24 hours.
    $card = Livewire::test(OrderCard::class, ['orderId' => $today->id]);
    expect($card->html())->toContain('href="tel:+79001112233"');
    $card->set('extendHours', 24)->call('act', 'ex');

    expect($today->refresh()->reserved_until->equalTo($untilBefore->copy()->addHours(24)))->toBeTrue()
        ->and($later->id)->toBeInt();

    // This session is not an admin session.
    nextRequest();
    $this->get('/admin')->assertRedirect();
    $this->get('/admin/orders')->assertRedirect();
    $this->get(route('admin.orders.print', $today))->assertRedirect();
    expect(Auth::guard('web')->check())->toBeFalse();

    // The journal: the action is there with the source Telegram.
    $entry = AdminAuditEntry::query()->where('action', AuditAction::OrderReservationExtended->value)->firstOrFail();
    expect($entry->source)->toBe(StatusChangeSource::Telegram)->and($entry->user_id)->toBe($user->id)->and($entry->data['hours'])->toBe(24);

    nextRequest();
    $this->flushSession();
    $this->actingAs(User::factory()->admin()->create());
    Livewire::test(ListAuditLog::class)->filterTable('source', 'telegram')->assertCanSeeTableRecords([$entry]);
});
