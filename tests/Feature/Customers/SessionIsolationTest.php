<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\User;
use App\Services\Customers\CustomerAuthService;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->customer = makeCustomer(['email' => 'session.owner@example.com']);
});

it('drops a session whose auth_version is behind the account (CA-31)', function (): void {
    signInCustomer($this, $this->customer);
    $this->get(route('account'))->assertOk();

    // Another device resets the password: the version in the database moves on.
    $this->customer->bumpAuthVersion();
    nextRequest();

    $this->get(route('account'))->assertRedirect(route('customer.login'));
    $this->assertGuest('customer');
    expect(session(CustomerAuthService::SESSION_VERSION_KEY))->toBeNull();
});

it('does not log out the staff session when it drops a stale customer session', function (): void {
    $staff = signInStaff($this, User::factory()->admin()->create());
    signInCustomer($this, $this->customer);
    $this->customer->bumpAuthVersion();
    nextRequest();

    $this->get(route('account'))->assertRedirect(route('customer.login'));

    $this->assertAuthenticatedAs($staff, 'web');
});

it('rotates the remember token with every version bump so «remember me» cookies die too (CA-31)', function (): void {
    $before = $this->customer->remember_token;

    $this->customer->bumpAuthVersion();

    expect($this->customer->fresh()->remember_token)->not->toBe($before)->and($this->customer->fresh()->auth_version)->toBe(2);
});

it('ends the session of a customer who got blocked meanwhile (CA-21)', function (): void {
    signInCustomer($this, $this->customer);
    $this->customer->forceFill(['is_blocked' => true])->save();
    nextRequest();

    $this->get(route('account'))->assertRedirect(route('customer.login'));
});

it('ends a session that has no stored version', function (): void {
    session()->put(Auth::guard('customer')->getName(), $this->customer->id);

    $this->get(route('account'))->assertRedirect(route('customer.login'));
});

it('keeps the staff sign-in when a customer signs in and out (CA-32)', function (): void {
    $staff = signInStaff($this, User::factory()->admin()->create());
    session()->put('staff_marker', 'kept');

    $this->post(route('customer.login.store'), ['email' => 'session.owner@example.com', 'password' => 'password-123'])->assertRedirect(route('account'));
    $this->assertAuthenticatedAs($staff, 'web');
    $this->assertAuthenticatedAs($this->customer, 'customer');
    expect(session('staff_marker'))->toBe('kept');

    nextRequest();
    $this->post(route('customer.logout'))->assertRedirect(route('home'));

    $this->assertGuest('customer');
    $this->assertAuthenticatedAs($staff, 'web');
    // The session was not invalidated: other keys of the browser session survive.
    expect(session('staff_marker'))->toBe('kept');
});

it('invalidates the whole session on customer logout when no staff member is signed in', function (): void {
    signInCustomer($this, $this->customer);
    session()->put('some_marker', 'gone');

    $this->post(route('customer.logout'))->assertRedirect(route('home'));

    expect(session('some_marker'))->toBeNull();
});

it('keeps the customer sign-in when staff sign out of the admin panel (CA-32)', function (): void {
    $staff = signInStaff($this, User::factory()->admin()->create());
    signInCustomer($this, $this->customer);
    session()->put('customer_marker', 'kept');

    $this->post('/'.config('shop.admin_path').'/logout');

    $this->assertGuest('web');
    expect(session('customer_marker'))->toBe('kept')
        ->and(session(Auth::guard('customer')->getName()))->toBe($this->customer->id);
});

it('uses separate session keys for the two guards (CA-32)', function (): void {
    expect(Auth::guard('customer')->getName())->not->toBe(Auth::guard('web')->getName());
});

it('does not let a signed-in customer into the admin panel (CA-32, D-071)', function (): void {
    signInCustomer($this, $this->customer);

    $this->get('/'.config('shop.admin_path'))->assertRedirect();
    expect($this->get('/'.config('shop.admin_path'))->headers->get('Location'))->toContain('login');

    expect(Customer::class)->not->toImplement(FilamentUser::class);
});

it('does not let staff into the cabinet', function (): void {
    signInStaff($this, User::factory()->admin()->create());

    $this->get(route('account'))->assertRedirect(route('customer.login'));
    $this->get(route('customer.profile'))->assertRedirect(route('customer.login'));
});

it('keeps customers and staff in different tables', function (): void {
    $user = User::factory()->create(['email' => 'same.address@example.com']);
    $customer = makeCustomer(['email' => 'same.address@example.com']);

    expect($user->getTable())->toBe('users')->and($customer->getTable())->toBe('customers');
});
