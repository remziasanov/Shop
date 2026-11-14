<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Filament\Pages\MyTelegram;
use App\Mail\OrderPlacedCustomerMail;
use App\Models\Order;
use App\Models\TelegramAccount;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Support\TelegramMode;
use Livewire\Livewire;

/**
 * TG-07, TG-08: «Получать статус в Telegram» on the thanks page and in the e-mail, «Подключить Telegram» in the cabinet
 * and in the staff profile.
 */
beforeEach(function (): void {
    $this->withoutVite();
});

describe('thanks page and e-mail (TG-08)', function (): void {
    it('offers the o_ link for all requests of the checkout when the bot is connected', function (): void {
        connectTelegramBot();
        $order = Order::factory()->order()->create();
        $reservation = Order::factory()->reservation()->create();
        $reservation->forceFill(['related_order_id' => $order->id])->save();
        $order->forceFill(['related_order_id' => $reservation->id])->save();

        $html = $this->get(route('order.thanks', $order->uuid))->assertOk()->assertSee('data-telegram-follow', false)->getContent();

        preg_match('#https://t\.me/test_shop_bot\?start=(o_[A-Za-z0-9_-]+)#', $html, $m);
        expect($m)->not->toBeEmpty();
        $token = TelegramLinkToken::query()->where('token_hash', hash('sha256', substr($m[1], 2)))->firstOrFail();
        expect($token->order_ids)->toEqualCanonicalizing([$order->id, $reservation->id]);
    });

    it('hides the button while the bot is not connected', function (): void {
        $order = Order::factory()->order()->create();

        $this->get(route('order.thanks', $order->uuid))->assertOk()->assertDontSee('data-telegram-follow', false);
        expect(TelegramLinkToken::query()->count())->toBe(0);
    });

    it('hides the button when customer notifications are switched off', function (): void {
        connectTelegramBot(['customer_notifications_enabled' => false]);
        $order = Order::factory()->order()->create();

        $this->get(route('order.thanks', $order->uuid))->assertDontSee('data-telegram-follow', false);
    });

    it('hides the button for a request already bound to a Telegram and in the Mini App session', function (): void {
        connectTelegramBot();
        $bound = Order::factory()->order()->create(['telegram_account_id' => TelegramAccount::factory()->create()->id]);
        $other = Order::factory()->order()->create();

        $this->get(route('order.thanks', $bound->uuid))->assertDontSee('data-telegram-follow', false);

        $account = TelegramAccount::factory()->create();
        $this->withSession([TelegramMode::SESSION_KEY => true, TelegramMode::ACCOUNT_KEY => $account->id, TelegramMode::USER_KEY => $account->telegram_user_id])
            ->get(route('order.thanks', $other->uuid))->assertDontSee('data-telegram-follow', false);
    });

    it('puts the link into the new-request e-mail to the customer', function (): void {
        connectTelegramBot();
        $order = Order::factory()->state(['type' => OrderType::Order, 'customer_email' => 'buyer@example.com'])->withItems(1)->create();

        $html = (new OrderPlacedCustomerMail($order))->render();

        expect($html)->toContain(__('telegram.order_link.button'))->toContain('https://t.me/test_shop_bot?start=o_');
    });

    it('leaves the e-mail unchanged while the bot is not connected', function (): void {
        $order = Order::factory()->state(['type' => OrderType::Order, 'customer_email' => 'buyer@example.com'])->withItems(1)->create();

        expect((new OrderPlacedCustomerMail($order))->render())->not->toContain('t.me');
    });
});

describe('cabinet (TG-07, TG-11)', function (): void {
    it('shows «Подключить Telegram» only while the bot is connected', function (): void {
        $customer = makeCustomer();
        signInCustomer($this, $customer);

        $this->get(route('customer.notifications'))->assertOk()->assertDontSee('data-telegram-block', false);

        connectTelegramBot();

        $this->get(route('customer.notifications'))->assertOk()->assertSee(__('telegram.cabinet.connect'));
    });

    it('sends the customer into the bot with a fresh c_ token', function (): void {
        connectTelegramBot();
        $customer = makeCustomer();
        signInCustomer($this, $customer);

        $response = $this->post(route('customer.telegram.connect'))->assertRedirectContains('https://t.me/test_shop_bot?start=c_');

        expect(TelegramLinkToken::query()->where('customer_id', $customer->id)->where('purpose', 'customer')->count())->toBe(1)
            ->and($response->headers->get('Location'))->toStartWith('https://t.me/');
    });

    it('requires a signed-in customer', function (): void {
        connectTelegramBot();

        $this->post(route('customer.telegram.connect'))->assertRedirect();
        expect(TelegramLinkToken::query()->count())->toBe(0);
    });

    it('shows the linked state and disconnects', function (): void {
        connectTelegramBot();
        $customer = makeCustomer();
        TelegramAccount::factory()->chatStarted()->create(['customer_id' => $customer->id, 'username' => 'ivan_tg']);
        signInCustomer($this, $customer);

        $this->get(route('customer.notifications'))->assertSee('@ivan_tg')->assertDontSee('data-telegram-start-hint', false);

        $this->post(route('customer.telegram.disconnect'))->assertRedirect(route('customer.notifications'));

        expect(TelegramAccount::query()->firstOrFail()->customer_id)->toBeNull();
    });

    it('keeps the block of a linked customer visible even when notifications are switched off', function (): void {
        connectTelegramBot(['customer_notifications_enabled' => false]);
        $customer = makeCustomer();
        TelegramAccount::factory()->chatStarted()->create(['customer_id' => $customer->id]);
        signInCustomer($this, $customer);

        $this->get(route('customer.notifications'))->assertSee(__('telegram.cabinet.disconnect'));
    });
});

describe('staff profile (TG-07 s_)', function (): void {
    it('issues an s_ link and shows the linked state', function (): void {
        connectTelegramBot();
        $user = User::factory()->manager()->create();
        $this->actingAs($user);

        Livewire::test(MyTelegram::class)
            ->assertSee(__('telegram.staff.title'))
            ->callAction('connect')
            ->assertSee('data-staff-telegram-link', false)
            ->assertSee('https://t.me/test_shop_bot?start=s_');

        expect(TelegramLinkToken::query()->where('user_id', $user->id)->where('purpose', 'staff')->count())->toBe(1);

        TelegramAccount::factory()->create(['user_id' => $user->id, 'username' => 'anna_tg']);
        $this->actingAs(User::query()->findOrFail($user->id));

        Livewire::test(MyTelegram::class)->assertSee('@anna_tg')->callAction('disconnect');

        expect(TelegramAccount::query()->firstOrFail()->user_id)->toBeNull();
    });

    it('says the bot is not connected', function (): void {
        $this->actingAs(User::factory()->manager()->create());

        Livewire::test(MyTelegram::class)->assertSee(__('telegram.staff.unavailable'));
    });

    it('is reachable from the panel user menu', function (): void {
        $this->actingAs(User::factory()->manager()->create());

        $this->get(MyTelegram::getUrl())->assertOk();
    });
});
