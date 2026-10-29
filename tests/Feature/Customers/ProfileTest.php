<?php

declare(strict_types=1);

use App\Enums\VerificationPurpose;
use App\Events\CustomerEmailVerified;
use App\Mail\CustomerCodeMail;
use App\Mail\CustomerEmailChangedMail;
use App\Services\Customers\CustomerAuthService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->customer = makeCustomer(['email' => 'profile.owner@example.com', 'name' => 'Старое Имя']);
    signInCustomer($this, $this->customer);
});

it('is closed to guests', function (): void {
    session()->flush();
    nextRequest();

    $this->get(route('customer.profile'))->assertRedirect(route('customer.login'));
    $this->post(route('customer.profile.update'), ['name' => 'X', 'phone' => '9001234567'])->assertRedirect(route('customer.login'));
});

it('shows the account landing page with the customer and the group (CA-19)', function (): void {
    $this->get(route('account'))
        ->assertOk()
        ->assertSee('Старое Имя')
        ->assertSee('profile.owner@example.com')
        ->assertSee('Розница')
        ->assertSee(route('customer.profile'), false);
});

it('updates the name and the normalized phone (CA-08)', function (): void {
    $this->post(route('customer.profile.update'), ['name' => 'Новое Имя', 'phone' => '8 (912) 345-67-89'])
        ->assertRedirect(route('customer.profile'));

    $fresh = $this->customer->fresh();
    expect($fresh->name)->toBe('Новое Имя')->and($fresh->phone)->toBe('+79123456789');
});

it('validates the profile data', function (): void {
    $this->post(route('customer.profile.update'), ['name' => '', 'phone' => '123'])->assertSessionHasErrors(['name', 'phone']);
});

it('cannot change e-mail, group or blocking through the profile form (mass assignment)', function (): void {
    $this->post(route('customer.profile.update'), [
        'name' => 'Новое Имя',
        'phone' => '9123456789',
        'email' => 'evil@example.com',
        'customer_group_id' => 999,
        'is_blocked' => '1',
        'auth_version' => 50,
    ]);

    $fresh = $this->customer->fresh();
    expect($fresh->email)->toBe('profile.owner@example.com')
        ->and($fresh->is_blocked)->toBeFalse()
        ->and($fresh->auth_version)->toBe(1)
        ->and($fresh->customer_group_id)->not->toBe(999);
});

it('changes the e-mail with a code sent to the new address, keeps this session, ends the others, tells the old address (CA-08)', function (): void {
    Event::fake([CustomerEmailVerified::class]);

    $this->post(route('customer.email.request'), ['email' => 'Brand.New@Example.com'])->assertRedirect(route('customer.email.confirm'));
    Mail::assertQueued(CustomerCodeMail::class, fn (CustomerCodeMail $mail): bool => $mail->hasTo('brand.new@example.com') && $mail->purpose === VerificationPurpose::ChangeEmail);
    expect($this->customer->fresh()->email)->toBe('profile.owner@example.com');

    $this->get(route('customer.email.confirm'))->assertOk();
    $this->post(route('customer.email.confirm.store'), ['code' => lastSentCode(VerificationPurpose::ChangeEmail)])
        ->assertRedirect(route('customer.profile'));

    $fresh = $this->customer->fresh();
    expect($fresh->email)->toBe('brand.new@example.com')
        ->and($fresh->auth_version)->toBe(2)
        ->and(session(CustomerAuthService::SESSION_VERSION_KEY))->toBe(2);
    Mail::assertQueued(CustomerEmailChangedMail::class, fn (CustomerEmailChangedMail $mail): bool => $mail->hasTo('profile.owner@example.com'));
    Event::assertDispatched(CustomerEmailVerified::class);

    // This session goes on, an older session (version 1) does not.
    nextRequest();
    $this->get(route('account'))->assertOk();
    session()->put(CustomerAuthService::SESSION_VERSION_KEY, 1);
    nextRequest();
    $this->get(route('account'))->assertRedirect(route('customer.login'));
});

it('does not change the e-mail with a wrong code', function (): void {
    $this->post(route('customer.email.request'), ['email' => 'brand.new@example.com']);

    $this->post(route('customer.email.confirm.store'), ['code' => '000000'])->assertSessionHasErrors('code');

    expect($this->customer->fresh()->email)->toBe('profile.owner@example.com');
});

it('does not take the code of another customer for the e-mail change', function (): void {
    $other = makeCustomer(['email' => 'second.owner@example.com']);
    $this->post(route('customer.email.request'), ['email' => 'brand.new@example.com']);
    $code = lastSentCode(VerificationPurpose::ChangeEmail);

    session()->flush();
    nextRequest();
    signInCustomer($this, $other);
    session()->put('customer_email_change', 'brand.new@example.com');

    $this->post(route('customer.email.confirm.store'), ['code' => $code])->assertSessionHasErrors('code');
    expect($other->fresh()->email)->toBe('second.owner@example.com');
});

it('refuses the same address and an address of another confirmed account', function (): void {
    makeCustomer(['email' => 'taken@example.com']);

    $this->post(route('customer.email.request'), ['email' => 'profile.owner@example.com'])->assertSessionHasErrors('email');
    $this->post(route('customer.email.request'), ['email' => 'taken@example.com'])->assertSessionHasErrors('email');
    Mail::assertNothingQueued();
});

it('replaces an unconfirmed account that only holds the new address', function (): void {
    makeCustomer(['email' => 'squatter@example.com', 'email_verified_at' => null]);

    $this->post(route('customer.email.request'), ['email' => 'squatter@example.com'])->assertRedirect(route('customer.email.confirm'));
    $this->post(route('customer.email.confirm.store'), ['code' => lastSentCode(VerificationPurpose::ChangeEmail)])->assertRedirect(route('customer.profile'));

    expect($this->customer->fresh()->email)->toBe('squatter@example.com');
});

it('changes the password with the current one and ends the other sessions (CA-08, CA-31)', function (): void {
    $this->post(route('customer.profile.password'), [
        'current_password' => 'password-123',
        'password' => 'brand-new-pass-7',
        'password_confirmation' => 'brand-new-pass-7',
    ])->assertRedirect(route('customer.profile'));

    $fresh = $this->customer->fresh();
    expect(Hash::check('brand-new-pass-7', $fresh->password))->toBeTrue()
        ->and($fresh->auth_version)->toBe(2)
        ->and(session(CustomerAuthService::SESSION_VERSION_KEY))->toBe(2);

    nextRequest();
    $this->get(route('account'))->assertOk();
});

it('refuses a password change with a wrong current password and limits the tries', function (): void {
    foreach (range(1, 5) as $_) {
        $this->post(route('customer.profile.password'), ['current_password' => 'wrong', 'password' => 'brand-new-pass-7', 'password_confirmation' => 'brand-new-pass-7'])
            ->assertSessionHasErrors('current_password');
    }

    $this->post(route('customer.profile.password'), ['current_password' => 'password-123', 'password' => 'brand-new-pass-7', 'password_confirmation' => 'brand-new-pass-7'])
        ->assertStatus(429);

    expect(Hash::check('password-123', $this->customer->fresh()->password))->toBeTrue();
});

it('limits e-mail change requests before it says that an address is taken (CA-05, CA-12)', function (): void {
    makeCustomer(['email' => 'taken@example.com']);

    // The refusal is an answer only behind the limits: the 5th request per address still gets it, the 6th gets 429.
    foreach (range(1, 5) as $_) {
        $this->post(route('customer.email.request'), ['email' => 'taken@example.com'])->assertSessionHasErrors('email');
        $this->travel(61)->seconds();
    }

    $this->post(route('customer.email.request'), ['email' => 'taken@example.com'])->assertStatus(429);
    Mail::assertNothingQueued();
});

it('limits e-mail change requests per customer across different addresses (CA-12)', function (): void {
    foreach (range(1, CustomerAuthService::EMAIL_CHANGES_PER_HOUR) as $i) {
        makeCustomer(['email' => "taken{$i}@example.com"]);
        $this->post(route('customer.email.request'), ['email' => "taken{$i}@example.com"])->assertSessionHasErrors('email');
    }

    $this->post(route('customer.email.request'), ['email' => 'free.address@example.com'])->assertStatus(429);
});
