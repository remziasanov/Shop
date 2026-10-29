<?php

declare(strict_types=1);

use App\Enums\WholesaleApplicationStatus;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Models\WholesaleApplication;
use App\Services\Customers\WholesaleApplicationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * CG-20, CG-24 (TZ 25.2): /account/wholesale for guests and customers, validation, spam protection and limits.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    RateLimiter::clear('sales-form:127.0.0.1');
    $this->customer = makeCustomer(['email' => 'buyer@example.com', 'name' => 'Пётр Покупатель', 'phone' => '+79001234567']);
});

/** @return array<string, mixed> */
function wholesaleBody(array $overrides = []): array
{
    return array_merge([
        'company_name' => 'ООО «Ромашка»',
        'inn' => '7707083893',
        'kpp' => '770701001',
        'contact_name' => 'Пётр Покупатель',
        'phone' => '8 (900) 123-45-67',
        'comment' => 'Берём оптом',
        'consent' => '1',
        'website' => '',
        'form_token' => formToken(),
    ], $overrides);
}

describe('access', function (): void {
    it('invites a guest to register or sign in', function (): void {
        $this->get(route('customer.wholesale'))
            ->assertOk()
            ->assertSee(__('wholesale.page.guest_text'))
            ->assertSee(route('customer.register'), false)
            ->assertSee(route('customer.login'), false)
            ->assertDontSee('name="inn"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    });

    it('sends a guest who posts the form to the sign-in page', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody())->assertRedirect(route('customer.login'));
        expect(WholesaleApplication::query()->count())->toBe(0);
    });

    it('shows a signed-in customer the form with the e-mail of the account and the honeypot', function (): void {
        signInCustomer($this, $this->customer);

        $this->get(route('customer.wholesale'))
            ->assertOk()
            ->assertSee('name="company_name"', false)
            ->assertSee('name="inn"', false)
            ->assertSee('name="kpp"', false)
            ->assertSee('name="website"', false)
            ->assertSee('name="form_token"', false)
            ->assertSee('buyer@example.com')
            ->assertSee('value="Пётр Покупатель"', false);
    });

    it('does not let a customer with an unconfirmed e-mail apply', function (): void {
        $unverified = makeCustomer(['email_verified_at' => null]);

        $reason = app(WholesaleApplicationService::class)->applyBlockReason($unverified);

        expect($reason)->toBe(__('wholesale.errors.unverified'));
    });

    it('does not let a staff session in', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        $this->post(route('customer.wholesale.store'), wholesaleBody())->assertRedirect(route('customer.login'));
    });
});

describe('submitting (CG-20)', function (): void {
    beforeEach(function (): void {
        signInCustomer($this, $this->customer);
    });

    it('creates the application and shows its status', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody())
            ->assertRedirect(route('customer.wholesale'))
            ->assertSessionHas('status', __('wholesale.form.sent'));

        $application = WholesaleApplication::query()->sole();
        expect($application->customer_id)->toBe($this->customer->id)
            ->and($application->phone)->toBe('+79001234567')
            ->and($application->email)->toBe('buyer@example.com')
            ->and($application->consent_ip)->not->toBeNull();

        nextRequest();
        $this->get(route('customer.wholesale'))
            ->assertOk()
            ->assertSee(__('wholesale.page.status_new'))
            ->assertDontSee('name="company_name"', false);
    });

    it('takes the e-mail from the account, not from the request', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody(['email' => 'someone.else@example.com', 'status' => 'approved', 'customer_id' => 999]));

        $application = WholesaleApplication::query()->sole();
        expect($application->email)->toBe('buyer@example.com')
            ->and($application->status)->toBe(WholesaleApplicationStatus::New)
            ->and($application->customer_id)->toBe($this->customer->id);
    });

    it('rejects a second application while one is pending', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody())->assertSessionHasNoErrors();
        nextRequest();

        $this->post(route('customer.wholesale.store'), wholesaleBody(['form_token' => formToken()]))
            ->assertSessionHasErrors('form');

        expect(WholesaleApplication::query()->count())->toBe(1);
    });

    it('rejects the fourth application within a month', function (): void {
        WholesaleApplication::factory()->count(3)->rejected()->forCustomer($this->customer)->create();

        $this->post(route('customer.wholesale.store'), wholesaleBody())->assertSessionHasErrors('form');

        expect(WholesaleApplication::query()->count())->toBe(3);
    });

    it('lets the customer apply again after a rejection and shows the reason', function (): void {
        WholesaleApplication::factory()->rejected('Нужны документы')->forCustomer($this->customer)->create();

        $this->get(route('customer.wholesale'))
            ->assertOk()
            ->assertSee('Нужны документы')
            ->assertSee('name="company_name"', false);
    });

    it('shows the approved group', function (): void {
        $opt = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
        $this->customer->forceFill(['customer_group_id' => $opt->id])->save();
        WholesaleApplication::factory()->approved($opt)->forCustomer($this->customer)->create();

        $this->get(route('customer.wholesale'))
            ->assertOk()
            ->assertSee('группа «Опт»')
            ->assertDontSee('name="company_name"', false);
    });
});

describe('validation', function (): void {
    beforeEach(function (): void {
        signInCustomer($this, $this->customer);
    });

    it('requires the company, INN, contact, phone and the consent', function (): void {
        $this->post(route('customer.wholesale.store'), ['form_token' => formToken()])
            ->assertSessionHasErrors(['company_name', 'inn', 'contact_name', 'phone', 'consent']);

        expect(WholesaleApplication::query()->count())->toBe(0);
    });

    it('checks the INN check digits', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody(['inn' => '7707083894']))
            ->assertSessionHasErrors(['inn' => __('requisites.validation.inn')]);
    });

    it('accepts an entrepreneur INN without a KPP and refuses a KPP with it', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody(['inn' => '500100732259', 'kpp' => '770701001']))
            ->assertSessionHasErrors(['kpp' => __('requisites.validation.kpp_only_for_legal_entity')]);

        $this->post(route('customer.wholesale.store'), wholesaleBody(['inn' => '500100732259', 'kpp' => '', 'form_token' => formToken()]))
            ->assertSessionHasNoErrors();
        expect(WholesaleApplication::query()->sole()->kpp)->toBeNull();
    });

    it('checks the KPP format and the phone', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody(['kpp' => '12', 'phone' => '123']))
            ->assertSessionHasErrors(['kpp', 'phone']);
    });
});

describe('spam protection (CG-24)', function (): void {
    beforeEach(function (): void {
        signInCustomer($this, $this->customer);
    });

    it('drops a submit that fills the honeypot', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody(['website' => 'http://spam.example']))
            ->assertSessionHasErrors('form');

        expect(WholesaleApplication::query()->count())->toBe(0);
    });

    it('drops a submit sent too fast or without the token', function (): void {
        $this->post(route('customer.wholesale.store'), wholesaleBody(['form_token' => formToken(0)]))->assertSessionHasErrors('form');
        $this->post(route('customer.wholesale.store'), wholesaleBody(['form_token' => '']))->assertSessionHasErrors('form');

        expect(WholesaleApplication::query()->count())->toBe(0);
    });

    it('limits the submits from one address', function (): void {
        // One application is stored, the next four are refused as «pending»; every one of them used an attempt.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('customer.wholesale.store'), wholesaleBody(['form_token' => formToken()]));
            nextRequest();
        }

        $this->post(route('customer.wholesale.store'), wholesaleBody())->assertSessionHasErrors(['form' => __('sales.guard.rate_limited')]);

        expect(WholesaleApplication::query()->count())->toBe(1);
    });
});

it('does not show one customer the application of another (CA-28)', function (): void {
    $other = Customer::factory()->create();
    WholesaleApplication::factory()->forCustomer($other)->create(['company_name' => 'ООО «Чужая»']);
    signInCustomer($this, $this->customer);

    $this->get(route('customer.wholesale'))->assertOk()->assertDontSee('Чужая')->assertSee('name="company_name"', false);
});
