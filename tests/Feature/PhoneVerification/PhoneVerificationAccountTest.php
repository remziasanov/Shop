<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/telegram_miniapp.php';

use App\Enums\PhoneProviderFailureKind;
use App\Enums\PhoneVerificationPurpose;
use App\Enums\VerificationPurpose;
use App\Livewire\Storefront\PhoneVerificationPanel;
use App\Models\Customer;
use App\Models\PhoneVerification;
use App\Models\TelegramAccount;
use App\Models\WholesaleApplication;
use App\Services\Customers\CustomerAnonymizer;
use App\Services\PhoneVerification\PhoneChallenge;
use App\Services\PhoneVerification\PhoneVerificationGate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PV-07 on the cabinet pages (registration, profile phone, wholesale application) and PV-13 а, б, е: the call starts
 * after the form is valid, the verified phone resets on change, a number of Telegram needs no call.
 */
const PVA_PHONE = '+79001234567';

beforeEach(function (): void {
    Mail::fake();
    resetFormGuard();
    $this->settings = enablePhoneVerification();
    $this->gateway = phoneStub();
});

/** Signs the customer in and makes the Livewire component see the session. */
function pvaSignIn(TestCase $test, array $attributes = []): Customer
{
    $customer = makeCustomer($attributes + ['phone' => PVA_PHONE, 'phone_verified_at' => null]);
    signInCustomer($test, $customer);
    $test->get(route('account'))->assertOk();

    return $customer;
}

function pvaWholesaleBody(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'ООО «Ромашка»', 'inn' => '7707083893', 'kpp' => '770701001', 'contact_name' => 'Пётр Покупатель',
        'phone' => '8 (900) 123-45-67', 'comment' => 'Берём оптом', 'consent' => '1', 'website' => '', 'form_token' => formToken(),
    ], $overrides);
}

describe('registration (PV-07, PV-13 а, б)', function (): void {
    it('does nothing when the scenario is off', function (): void {
        $this->post(route('customer.register.store'), registrationBody());
        $this->post(route('customer.confirm.store'), ['code' => lastSentCode(VerificationPurpose::Register)])->assertRedirect(route('account'));

        expect($this->gateway->calls)->toBe([]);
        $this->get(route('account'))->assertOk()->assertDontSee('data-phone-prompt', false);
    });

    it('calls only after the e-mail is confirmed: the registration form itself never calls (PV-13 б)', function (): void {
        $this->settings->registration = 'required';
        $this->settings->save();

        $this->post(route('customer.register.store'), registrationBody())->assertRedirect(route('customer.confirm'));
        expect($this->gateway->calls)->toBe([]);

        $this->post(route('customer.confirm.store'), ['code' => lastSentCode(VerificationPurpose::Register)])->assertRedirect(route('customer.phone'));
        expect($this->gateway->calls)->toBe([]);

        // The cabinet is open, the page offers the verification and warns that requests wait.
        $this->get(route('account'))->assertOk()->assertSee('data-phone-prompt', false)->assertSee('оформить нельзя');
        $this->get(route('customer.phone'))->assertOk()->assertSee('data-phone-panel', false);
    });

    it('verifies the profile phone with the call and then opens the requests (PV-13 а)', function (): void {
        $this->settings->registration = 'required';
        $this->settings->save();
        $customer = pvaSignIn($this);

        $panel = Livewire::test(PhoneVerificationPanel::class);
        expect($panel->get('purposeValue'))->toBe('registration');

        $panel->call('startVerification')->assertSet('pvActive', true);
        expect($this->gateway->types())->toBe(['flash'])->and($this->gateway->calls[0]['phone'])->toBe(PVA_PHONE);

        $panel->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode')->assertRedirect(route('account'));

        $customer->refresh();
        expect($customer->phone_verified_at)->not->toBeNull()
            ->and(PhoneVerification::query()->firstOrFail()->customer_id)->toBe($customer->id)
            ->and(app(PhoneVerificationGate::class)->accountBlocked($customer))->toBeFalse();
    });

    it('takes the verified number of the customer\'s Telegram without a call (TG-09)', function (): void {
        $this->settings->registration = 'later';
        $this->settings->save();
        $account = TelegramAccount::factory()->create(['phone' => PVA_PHONE, 'phone_verified_at' => now()]);
        $this->withSession(tgSession($account));
        $customer = pvaSignIn($this);

        Livewire::test(PhoneVerificationPanel::class)->call('startVerification')->assertRedirect(route('account'));

        expect($customer->refresh()->phone_verified_at)->not->toBeNull()->and($this->gateway->calls)->toBe([]);
    });

    it('offers it in «можно позже» but keeps the account open', function (): void {
        $this->settings->registration = 'later';
        $this->settings->save();
        $customer = pvaSignIn($this);

        $this->get(route('account'))->assertOk()->assertSee('data-phone-prompt', false)->assertSee('необязательно');
        expect(app(PhoneVerificationGate::class)->accountBlocked($customer))->toBeFalse();
    });

    it('lifts the lock for the session when the provider failed and the owner skips (PV-05)', function (): void {
        $this->settings->registration = 'required';
        $this->settings->save();
        $customer = pvaSignIn($this);
        $this->gateway->failWith(PhoneProviderFailureKind::Server);

        Livewire::test(PhoneVerificationPanel::class)->call('startVerification')->assertRedirect(route('account'));

        expect($customer->refresh()->phone_verified_at)->toBeNull()
            ->and(app(PhoneVerificationGate::class)->accountBlocked($customer))->toBeFalse();
    });

    it('renders in the Mini App layout too', function (): void {
        $this->settings->registration = 'later';
        $this->settings->save();
        $account = TelegramAccount::factory()->create();
        $this->withSession(tgSession($account));
        pvaSignIn($this);

        $this->get(route('customer.phone'))->assertOk()->assertSee('data-phone-panel', false)->assertSee('data-tg-colors', false);
    });

    it('shows no verification page and no prompt for a verified account', function (): void {
        $this->settings->registration = 'required';
        $this->settings->save();
        pvaSignIn($this, ['phone_verified_at' => now()]);

        $this->get(route('account'))->assertOk()->assertDontSee('data-phone-prompt', false)->assertSee('Телефон подтверждён');
        $this->get(route('customer.phone'))->assertRedirect(route('customer.profile'));
    });
});

describe('profile phone (PV-07, PV-13 б, е)', function (): void {
    it('saves a changed phone only after the verification and marks it verified', function (): void {
        $this->settings->profile_change = true;
        $this->settings->save();
        $customer = pvaSignIn($this, ['phone_verified_at' => now()]);

        $this->post(route('customer.profile.update'), ['name' => 'Новое Имя', 'phone' => '8 (900) 765-43-21'])->assertRedirect(route('customer.phone'));

        // Nothing is saved and nobody is called until the customer presses the button on the next page.
        expect($customer->refresh()->phone)->toBe(PVA_PHONE)->and($customer->name)->not->toBe('Новое Имя')->and($this->gateway->calls)->toBe([]);

        $panel = Livewire::test(PhoneVerificationPanel::class)->call('startVerification')->assertSet('pvActive', true);
        expect($this->gateway->calls[0]['phone'])->toBe('+79007654321');

        $panel->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode')->assertRedirect(route('customer.profile'));

        $customer->refresh();
        expect($customer->phone)->toBe('+79007654321')->and($customer->name)->toBe('Новое Имя')->and($customer->phone_verified_at)->not->toBeNull()
            ->and(session(PhoneChallenge::KEY))->toBeNull();
    });

    it('calls nobody when the number is the same as now (PV-13 б)', function (): void {
        $this->settings->profile_change = true;
        $this->settings->save();
        $customer = pvaSignIn($this, ['phone_verified_at' => now()]);

        $this->post(route('customer.profile.update'), ['name' => 'Новое Имя', 'phone' => '+7 900 123 45 67'])->assertRedirect(route('customer.profile'));

        expect($customer->refresh()->name)->toBe('Новое Имя')->and($customer->phone_verified_at)->not->toBeNull()->and($this->gateway->calls)->toBe([]);
    });

    it('resets the verification when the phone changes and the scenario is off (PV-13 е)', function (): void {
        $customer = pvaSignIn($this, ['phone_verified_at' => now()]);

        $this->post(route('customer.profile.update'), ['name' => 'Имя', 'phone' => '8 (900) 765-43-21'])->assertRedirect(route('customer.profile'));

        expect($customer->refresh()->phone)->toBe('+79007654321')->and($customer->phone_verified_at)->toBeNull();
    });

    it('drops the parked change with «Отменить»', function (): void {
        $this->settings->profile_change = true;
        $this->settings->save();
        $customer = pvaSignIn($this);
        $this->post(route('customer.profile.update'), ['name' => 'Имя', 'phone' => '8 (900) 765-43-21']);

        Livewire::test(PhoneVerificationPanel::class)->call('discard')->assertRedirect(route('customer.profile'));

        expect($customer->refresh()->phone)->toBe(PVA_PHONE)->and(session(PhoneChallenge::KEY))->toBeNull();
    });

    it('does not take another customer\'s parked change', function (): void {
        $this->settings->profile_change = true;
        $this->settings->save();
        $first = pvaSignIn($this);
        $this->post(route('customer.profile.update'), ['name' => 'Имя', 'phone' => '8 (900) 765-43-21']);
        $other = makeCustomer(['phone' => '+79005550000']);
        signInCustomer($this, $other);
        nextRequest();

        $this->get(route('customer.phone'))->assertRedirect(route('customer.profile'));
        expect($first->refresh()->phone)->toBe(PVA_PHONE);
    });

    it('saves the number of Telegram into the profile and sets the verification (PV-13 е, TG-09)', function (): void {
        $account = TelegramAccount::factory()->create(['phone' => '+79007654321', 'phone_verified_at' => now()]);
        $this->withSession(tgSession($account));
        $customer = pvaSignIn($this, ['phone_verified_at' => null]);

        $this->get(route('customer.profile'))->assertOk()->assertSee('data-telegram-phone', false)->assertSee('Сохранить номер из Telegram в профиль');
        $this->post(route('customer.profile.telegram-phone'))->assertRedirect(route('customer.profile'));

        expect($customer->refresh()->phone)->toBe('+79007654321')->and($customer->phone_verified_at)->not->toBeNull()->and($this->gateway->calls)->toBe([]);
        $this->get(route('customer.profile'))->assertOk()->assertDontSee('data-telegram-phone', false);
    });

    it('has no «save Telegram number» outside the Mini App', function (): void {
        pvaSignIn($this);

        $this->get(route('customer.profile'))->assertOk()->assertDontSee('data-telegram-phone', false);
        $this->post(route('customer.profile.telegram-phone'))->assertNotFound();
    });
});

describe('wholesale application (PV-07)', function (): void {
    beforeEach(function (): void {
        $this->settings->wholesale = true;
        $this->settings->save();
    });

    it('parks the validated application and sends it after the code', function (): void {
        $customer = pvaSignIn($this);

        $this->post(route('customer.wholesale.store'), pvaWholesaleBody())->assertRedirect(route('customer.phone'));
        expect(WholesaleApplication::query()->count())->toBe(0)->and($this->gateway->calls)->toBe([]);

        $panel = Livewire::test(PhoneVerificationPanel::class)->call('startVerification')->assertSet('pvActive', true);
        $panel->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode')->assertRedirect(route('customer.wholesale'));

        $application = WholesaleApplication::query()->firstOrFail();
        expect($application->customer_id)->toBe($customer->id)->and($application->company_name)->toBe('ООО «Ромашка»')
            ->and(PhoneVerification::query()->firstOrFail()->purpose)->toBe(PhoneVerificationPurpose::Wholesale);
    });

    it('does not call for an invalid form (PV-13 б)', function (): void {
        pvaSignIn($this);

        $this->post(route('customer.wholesale.store'), pvaWholesaleBody(['inn' => '123']))->assertSessionHasErrors('inn');

        expect($this->gateway->calls)->toBe([])->and(session(PhoneChallenge::KEY))->toBeNull();
    });

    it('sends the application at once when the phone is the verified phone of the account (PV-13 в)', function (): void {
        pvaSignIn($this, ['phone_verified_at' => now()]);

        $this->post(route('customer.wholesale.store'), pvaWholesaleBody())->assertRedirect(route('customer.wholesale'));

        expect(WholesaleApplication::query()->count())->toBe(1)->and($this->gateway->calls)->toBe([]);
    });

    it('is not verified at all when the scenario is off', function (): void {
        $this->settings->wholesale = false;
        $this->settings->save();
        pvaSignIn($this);

        $this->post(route('customer.wholesale.store'), pvaWholesaleBody())->assertRedirect(route('customer.wholesale'));

        expect(WholesaleApplication::query()->count())->toBe(1)->and($this->gateway->calls)->toBe([]);
    });

    it('sends the application without verification when the provider failed and the owner skips (PV-05)', function (): void {
        pvaSignIn($this);
        $this->gateway->failWith(PhoneProviderFailureKind::Timeout);
        $this->post(route('customer.wholesale.store'), pvaWholesaleBody());

        Livewire::test(PhoneVerificationPanel::class)->call('startVerification')->assertRedirect(route('customer.wholesale'));

        expect(WholesaleApplication::query()->count())->toBe(1);
    });
});

describe('reset of phone_verified_at (PV-13 е)', function (): void {
    it('resets it for a re-registration of an unconfirmed account (CA-05)', function (): void {
        $this->post(route('customer.register.store'), registrationBody());
        $customer = Customer::query()->firstOrFail();
        $customer->forceFill(['phone_verified_at' => now()])->save();
        $this->travel(61)->seconds();

        $this->post(route('customer.register.store'), registrationBody(['phone' => '+7 901 000-00-01', 'form_token' => formToken()]))->assertRedirect(route('customer.confirm'));
        $this->post(route('customer.confirm.store'), ['code' => lastSentCode(VerificationPurpose::Register)]);

        expect($customer->refresh()->phone)->toBe('+79010000001')->and($customer->phone_verified_at)->toBeNull();
    });

    it('resets it and deletes the records on anonymization (CA-17, PV-08)', function (): void {
        $customer = makeCustomer(['phone' => PVA_PHONE, 'phone_verified_at' => now()]);
        PhoneVerification::factory()->create(['customer_id' => $customer->id, 'phone' => PVA_PHONE]);

        app(CustomerAnonymizer::class)->anonymize($customer);

        expect($customer->refresh()->phone_verified_at)->toBeNull()->and($customer->phone)->toBeNull()->and(PhoneVerification::query()->count())->toBe(0);
    });
});

describe('a verified number never marks another one (PV-07, PV-13 е)', function (): void {
    it('applies nothing when a profile change to another number was parked after the verification started', function (): void {
        $this->settings->registration = 'required';
        $this->settings->profile_change = true;
        $this->settings->save();
        $customer = pvaSignIn($this);

        $panel = Livewire::test(PhoneVerificationPanel::class)->call('startVerification')->assertSet('pvActive', true);
        expect($this->gateway->calls)->toHaveCount(1)->and($this->gateway->calls[0]['phone'])->toBe(PVA_PHONE);

        // Second tab: the profile form parks a change to somebody else's number.
        app(PhoneChallenge::class)->parkProfileChange(PhoneVerificationGate::session(), $customer, 'Чужой', '+79009998877');

        $panel->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode')->assertRedirect(route('customer.profile'));

        $customer->refresh();
        expect($customer->phone)->toBe(PVA_PHONE)->and($customer->name)->not->toBe('Чужой')->and($customer->phone_verified_at)->toBeNull()
            ->and(session(PhoneChallenge::KEY))->toBeNull();
    });

    it('does not mark a profile phone verified when it was changed after the verification started', function (): void {
        $this->settings->registration = 'later';
        $this->settings->save();
        $customer = pvaSignIn($this);

        $panel = Livewire::test(PhoneVerificationPanel::class)->call('startVerification')->assertSet('pvActive', true);

        // The profile is saved with another number meanwhile (this resets phone_verified_at).
        $customer->forceFill(['phone' => '+79009998877', 'phone_verified_at' => null])->save();

        $panel->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode')->assertRedirect(route('customer.profile'));

        $customer->refresh();
        expect($customer->phone)->toBe('+79009998877')->and($customer->phone_verified_at)->toBeNull()
            ->and($this->gateway->calls)->toHaveCount(1);
    });

    it('does not run a parked wholesale application of another number', function (): void {
        $this->settings->registration = 'later';
        $this->settings->save();
        $customer = pvaSignIn($this);

        $panel = Livewire::test(PhoneVerificationPanel::class)->call('startVerification');
        app(PhoneChallenge::class)->parkWholesale(PhoneVerificationGate::session(), $customer, [
            'company_name' => 'ООО', 'inn' => '7707083893', 'kpp' => null, 'contact_name' => 'X', 'phone' => '+79009998877', 'comment' => null,
        ]);

        $panel->set('pvCode', $this->gateway->lastCode)->call('confirmPhoneCode');

        expect(WholesaleApplication::query()->count())->toBe(0)->and($customer->refresh()->phone_verified_at)->toBeNull();
    });
});
