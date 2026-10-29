<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageNotificationSettings;
use App\Filament\Resources\WholesaleApplications\WholesaleApplicationResource;
use App\Jobs\SendWholesaleApplicationNotification;
use App\Mail\WholesaleApplicationShopMail;
use App\Models\Customer;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Customers\CustomerAuthService;
use App\Services\Customers\RegistrationData;
use App\Services\Customers\WholesaleApplicationService;
use App\Settings\NotificationSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * CG-21 (TZ 25.2): the shop hears about a new application by e-mail and Telegram, two independent toggles; the
 * registrations of customers notify nobody (D-088).
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $this->customer = makeCustomer(['email' => 'buyer@example.com']);
});

/** @param array<string, mixed> $values */
function shopNotifications(array $values = []): NotificationSettings
{
    $settings = app(NotificationSettings::class);
    $settings->notify_emails = ['shop@example.com', 'second@example.com'];
    $settings->telegram_enabled = true;
    $settings->telegram_bot_token = '123456:SECRET-token_abc';
    $settings->telegram_chat_id = '-1001';
    $settings->notify_wholesale_email = true;
    $settings->notify_wholesale_telegram = true;

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    $settings->save();

    return $settings;
}

function applyForWholesale(Customer $customer, array $data = []): WholesaleApplication
{
    return app(WholesaleApplicationService::class)->submit($customer, array_merge([
        'company_name' => 'ООО «Ромашка»',
        'inn' => '7707083893',
        'kpp' => '770701001',
        'contact_name' => 'Пётр Покупатель',
        'phone' => '+79001234567',
        'comment' => null,
    ], $data), '192.0.2.10', 'TestBrowser/1.0');
}

it('has both toggles on by default (settings migration)', function (): void {
    $settings = app(NotificationSettings::class);

    expect($settings->notify_wholesale_email)->toBeTrue()->and($settings->notify_wholesale_telegram)->toBeTrue();
});

it('sends an e-mail to every shop address and a Telegram message', function (): void {
    shopNotifications();

    $application = applyForWholesale($this->customer);

    Mail::assertSent(WholesaleApplicationShopMail::class, 2);
    Mail::assertSent(WholesaleApplicationShopMail::class, fn (WholesaleApplicationShopMail $mail): bool => $mail->hasTo('shop@example.com'));
    Mail::assertSent(WholesaleApplicationShopMail::class, fn (WholesaleApplicationShopMail $mail): bool => $mail->hasTo('second@example.com'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/bot123456:SECRET-token_abc/sendMessage')
        && str_contains((string) $request['text'], 'ООО «Ромашка»')
        && str_contains((string) $request['text'], WholesaleApplicationResource::getUrl('view', ['record' => $application])));
});

it('sends only e-mail when the Telegram toggle is off', function (): void {
    shopNotifications(['notify_wholesale_telegram' => false]);

    applyForWholesale($this->customer);

    Mail::assertSent(WholesaleApplicationShopMail::class, 2);
    Http::assertNothingSent();
});

it('sends only Telegram when the e-mail toggle is off', function (): void {
    shopNotifications(['notify_wholesale_email' => false]);

    applyForWholesale($this->customer);

    Mail::assertNotSent(WholesaleApplicationShopMail::class);
    Http::assertSentCount(1);
});

it('sends nothing when both toggles are off', function (): void {
    shopNotifications(['notify_wholesale_email' => false, 'notify_wholesale_telegram' => false]);

    applyForWholesale($this->customer);

    Mail::assertNothingSent();
    Http::assertNothingSent();
});

it('does not use the order toggles', function (): void {
    shopNotifications(['notify_shop_email' => false, 'notify_shop_telegram' => false, 'notify_event_order' => false]);

    applyForWholesale($this->customer);

    Mail::assertSent(WholesaleApplicationShopMail::class, 2);
    Http::assertSentCount(1);
});

it('skips Telegram when it is not configured', function (): void {
    shopNotifications(['telegram_enabled' => false]);

    applyForWholesale($this->customer);

    Http::assertNothingSent();
    Mail::assertSent(WholesaleApplicationShopMail::class, 2);
});

it('queues one job per channel and recipient', function (): void {
    Queue::fake();
    shopNotifications();

    applyForWholesale($this->customer);

    Queue::assertPushed(SendWholesaleApplicationNotification::class, 3);
    Queue::assertPushed(SendWholesaleApplicationNotification::class, fn (SendWholesaleApplicationNotification $job): bool => $job->kind === 'telegram');
});

it('keeps the application when the notification fails', function (): void {
    shopNotifications();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

    $application = applyForWholesale($this->customer);

    expect(WholesaleApplication::query()->whereKey($application->id)->exists())->toBeTrue();
});

it('writes the mail with the data of the application, the link to it and the duplicate warning', function (): void {
    shopNotifications(['notify_wholesale_telegram' => false]);
    $first = WholesaleApplication::factory()->create(['inn' => '7707083893']);

    $application = applyForWholesale($this->customer, ['comment' => 'Нужен [прайс](https://evil.example/x)']);

    Mail::assertSent(WholesaleApplicationShopMail::class, function (WholesaleApplicationShopMail $mail) use ($application, $first): bool {
        $html = $mail->render();

        return str_contains($html, 'ООО «Ромашка»')
            && str_contains($html, '7707083893')
            && str_contains($html, '+79001234567')
            && str_contains($html, WholesaleApplicationResource::getUrl('view', ['record' => $application]))
            && str_contains($html, 'Этот ИНН уже в заявке #'.$first->id)
            && ! str_contains($html, 'href="https://evil.example/x"');
    });
});

it('escapes the values of the Telegram message', function (): void {
    shopNotifications(['notify_wholesale_email' => false]);

    applyForWholesale($this->customer, ['company_name' => 'ООО <b>&</b> Co']);

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['text'], 'ООО &lt;b&gt;&amp;&lt;/b&gt; Co') && $request['parse_mode'] === 'HTML');
});

it('does not notify the shop about registrations or about decisions (CG-21, D-088)', function (): void {
    shopNotifications();

    app(CustomerAuthService::class)->register(new RegistrationData(
        name: 'Новый Покупатель',
        email: 'new.buyer@example.com',
        phone: '+79005554433',
        password: 'secret-pass-1',
        ip: '192.0.2.20',
    ));

    Mail::assertNotSent(WholesaleApplicationShopMail::class);
    Http::assertNothingSent();

    $application = applyForWholesale($this->customer);
    Mail::assertSent(WholesaleApplicationShopMail::class, 2);

    app(WholesaleApplicationService::class)->reject($application, 'Нет', User::factory()->manager()->create());
    Mail::assertSent(WholesaleApplicationShopMail::class, 2);
    Http::assertSentCount(1);
});

describe('settings page', function (): void {
    it('has the two toggles and saves them independently', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageNotificationSettings::class)
            ->assertFormFieldExists('notify_wholesale_email')
            ->assertFormFieldExists('notify_wholesale_telegram')
            ->fillForm(['notify_wholesale_email' => false, 'notify_wholesale_telegram' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(NotificationSettings::class);
        expect($settings->notify_wholesale_email)->toBeFalse()->and($settings->notify_wholesale_telegram)->toBeTrue();
    });

    it('labels them as the TZ names them', function (): void {
        expect(__('shop.settings.notifications.fields.notify_wholesale_email'))->toBe('Заявки на опт: email')
            ->and(__('shop.settings.notifications.fields.notify_wholesale_telegram'))->toBe('Заявки на опт: Telegram');
    });
});
