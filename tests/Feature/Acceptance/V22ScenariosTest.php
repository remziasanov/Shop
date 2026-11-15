<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_miniapp.php';

use App\Enums\PhoneProviderFailureKind;
use App\Enums\PhoneVerificationError;
use App\Enums\PhoneVerificationPurpose;
use App\Enums\SalesMode;
use App\Exceptions\PhoneVerificationException;
use App\Filament\Pages\Settings\ManagePhoneVerificationSettings;
use App\Jobs\SendPhoneVerificationAlert;
use App\Livewire\Storefront\ReservationForm;
use App\Models\Order;
use App\Models\PhoneVerification;
use App\Models\Product;
use App\Models\TelegramAccount;
use App\Models\User;
use App\Services\PhoneVerification\PhoneVerificationService;
use App\Settings\NotificationSettings;
use App\Settings\PhoneVerificationSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * TZ section 14, scenario 29 (version 2.2): phone verification. The provider is the stub of the PV tests in both of its
 * variants (the client's code, and the code of the provider); nothing reaches a real provider. A real call to a phone and
 * the SMS fallback are the manual checklist of docs/PROGRESS.md, stage 19.
 */
beforeEach(function (): void {
    resetFormGuard();
    RateLimiter::clear('login');
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'min_order_amount' => 0, 'reservation_hours' => 48]);
});

afterEach(fn () => Carbon::setTestNow());

/** A guest fills the reservation form of the product and presses the button. */
function v22Reserve(Product $product, string $phone = '8 (900) 123-45-67')
{
    $form = Livewire::test(ReservationForm::class, ['productId' => $product->id]);
    test()->travel(5)->seconds();

    return $form->set('qty', 1)->set('name', 'Иван Тестов')->set('phone', $phone)->set('consent', true)->call('submit');
}

it('29. phone verification: the key and the test call, a reserve with a call, wrong digits, the daily number limit, a provider failure, the daily shop limit, the number from Telegram', function (bool $callerCode): void {
    $gateway = phoneStub($callerCode);
    $code = fn (): string => $callerCode ? (string) $gateway->lastCode : $gateway->providerCode;

    // --- The administrator enters the key of the provider, checks the connection and tests it on their own phone.
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    enablePhoneVerification();
    $gateway->balance = '312.50';

    $page = Livewire::test(ManagePhoneVerificationSettings::class)
        ->callAction(TestAction::make('checkConnection')->schemaComponent('connectionActions', schema: 'form'))
        ->assertNotified(__('phone_verification.admin.notifications.check_ok', ['balance' => '312.50']));
    $page->callAction(TestAction::make('sendTest')->schemaComponent('connectionActions', schema: 'form'), ['phone' => '+7 999 000-11-22'])
        ->assertNotified(__('phone_verification.admin.notifications.test_sent'));
    expect($gateway->types())->toBe(['flash'])->and($gateway->calls[0]['phone'])->toBe('+79990001122');

    // --- Then they switch the scenario «оформление гостем — только резервы» on.
    Livewire::test(ManagePhoneVerificationSettings::class)
        ->fillForm(['enabled' => true, 'guest_checkout' => 'reservations', 'daily_limit' => 200])
        ->call('save')
        ->assertHasNoFormErrors();
    app()->forgetInstance(PhoneVerificationSettings::class);
    $this->app['auth']->guard('web')->logout();

    // --- A guest reserves: the call, the 4 digits, the reserve is created with the mark «телефон подтверждён».
    $product = sellable(['sales_mode' => SalesMode::Reservation, 'stock_qty' => 50, 'base_price' => '500.00']);
    $form = v22Reserve($product)->assertSet('pvActive', true);
    expect(Order::query()->count())->toBe(0)->and($gateway->types())->toBe(['flash', 'flash']);

    $form->set('pvCode', $code())->call('confirmPhoneCode');
    $first = Order::query()->firstOrFail();
    expect($first->phone_verified)->toBeTrue()->and($first->type->value)->toBe('reservation')->and($first->customer_phone)->toBe('+79001234567');

    // --- Wrong digits five times: the code is spent, a new call is needed.
    session()->flush();
    $this->travel(61)->seconds();
    $form = v22Reserve($product)->assertSet('pvActive', true);
    $wrong = $code() === '1111' ? '2222' : '1111';

    foreach (range(1, 5) as $i) {
        $form->set('pvCode', $wrong)->call('confirmPhoneCode')->assertHasErrors(['pvCode']);
    }

    $form->set('pvCode', $code())->call('confirmPhoneCode')->assertHasErrors(['pvCode']);
    expect(Order::query()->count())->toBe(1);

    $this->travel(61)->seconds();
    $form->call('resendPhoneCall')->assertHasNoErrors();
    $form->set('pvCode', $code())->call('confirmPhoneCode');
    expect(Order::query()->count())->toBe(2)->and(Order::query()->where('phone_verified', true)->count())->toBe(2);

    // --- The seventh request of a day for the same number is refused (3 per hour, 6 per day).
    $service = app(PhoneVerificationService::class);
    $number = '+79001234567';
    $requests = PhoneVerification::query()->where('phone', $number)->count();
    expect($requests)->toBe(3);

    foreach (range(1, 3) as $i) {
        $this->travel(61)->minutes();
        $service->start($number, PhoneVerificationPurpose::GuestCheckout, phoneSession(), '192.0.2.'.$i);
    }

    $this->travel(61)->minutes();

    try {
        $service->start($number, PhoneVerificationPurpose::GuestCheckout, phoneSession(), '192.0.2.99');
        $refused = null;
    } catch (PhoneVerificationException $e) {
        $refused = $e;
    }

    expect($refused)->not->toBeNull()->and($refused->reason)->toBe(PhoneVerificationError::Throttled)->and($refused->isThrottle())->toBeTrue()
        ->and(PhoneVerification::query()->where('phone', $number)->count())->toBe(6);

    // --- A wrong key of the provider: the reserve is created without the verification, the shop gets ONE warning.
    Queue::fake();
    $notifications = app(NotificationSettings::class);
    $notifications->notify_emails = ['owner@example.com'];
    $notifications->notify_shop_email = true;
    $notifications->notify_shop_telegram = false;
    $notifications->save();
    session()->flush();
    $gateway->failWith(PhoneProviderFailureKind::Auth);
    $before = Order::query()->count();

    v22Reserve($product, '8 (900) 765-43-21')->assertHasNoErrors();
    $skipped = Order::query()->latest('id')->firstOrFail();
    expect(Order::query()->count())->toBe($before + 1)->and($skipped->phone_verified)->toBeFalse();
    Queue::assertPushed(SendPhoneVerificationAlert::class, 1);

    session()->flush();
    $this->travel(61)->seconds();
    v22Reserve($product, '8 (900) 765-43-21')->assertHasNoErrors();
    Queue::assertPushed(SendPhoneVerificationAlert::class, 1);
    $gateway->throw = null;

    // --- The daily limit of the shop is reached: the verification is blocked, not skipped.
    session()->flush();
    $settings = app(PhoneVerificationSettings::class);
    $settings->daily_limit = $service->dailyUsage();
    $settings->save();
    $count = Order::query()->count();

    v22Reserve($product, '8 (900) 111-22-33')->assertHasErrors(['phone'])->assertSet('pvActive', false);
    expect(Order::query()->count())->toBe($count);

    // --- In the Mini App the number of Telegram is verified without a call.
    $settings->daily_limit = 200;
    $settings->save();
    $calls = count($gateway->calls);
    $account = TelegramAccount::factory()->create(['phone' => '+79005550000', 'phone_verified_at' => now()]);
    session()->flush();
    $this->withSession(tgSession($account));

    v22Reserve($product, '8 (900) 555-00-00')->assertHasNoErrors()->assertSet('pvActive', false);

    expect(Order::query()->count())->toBe($count + 1)->and(Order::query()->latest('id')->firstOrFail()->phone_verified)->toBeTrue()
        ->and(count($gateway->calls))->toBe($calls);
})->with([
    'the provider takes the code of the shop' => true,
    'the provider makes the code itself' => false,
]);
