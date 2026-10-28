<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Enums\PriceDisplayMode;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Services\Access\ViewerContext;
use App\Support\Money;
use Illuminate\Support\Facades\Mail;

/**
 * ViewerContext::resolveGroup() with the `customer` guard (CG-16, D-094): a signed-in customer is seen as their group,
 * a guest as «Гость». The access matrix itself is covered by tests/Feature/Access.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Mail::fake();
    $this->open = Category::factory()->create(['name' => 'Открытая']);
    $this->secret = Category::factory()->create(['name' => 'Секретная']);
    $this->visible = groupProduct($this->open, 'Видимый чайник');
    $this->hidden = groupProduct($this->secret, 'Скрытый чайник');
});

/** The group the viewer resolves to during the next request. */
function viewerGroupAfterRequest(): CustomerGroup
{
    test()->get('/');

    return app(ViewerContext::class)->group();
}

it('sees a guest as «Гость» and has no customer', function (): void {
    $this->get('/');

    expect(app(ViewerContext::class)->group()->code)->toBe('guest')
        ->and(app(ViewerContext::class)->customer())->toBeNull()
        ->and(app(ViewerContext::class)->isGuest())->toBeTrue();
});

it('sees a signed-in customer as their group (CG-16)', function (): void {
    $group = CustomerGroup::factory()->create(['name' => 'Постоянные']);
    signInCustomer($this, makeCustomer(['customer_group_id' => $group->id]));

    expect(viewerGroupAfterRequest()->id)->toBe($group->id)
        ->and(app(ViewerContext::class)->isGuest())->toBeFalse()
        ->and(app(ViewerContext::class)->customer())->not->toBeNull();
});

it('sends the customer of a deactivated group to «Розница» (CG-16)', function (): void {
    $group = CustomerGroup::factory()->create(['is_active' => false]);
    signInCustomer($this, makeCustomer(['customer_group_id' => $group->id]));

    expect(viewerGroupAfterRequest()->code)->toBe('retail');
});

it('treats a blocked or unconfirmed customer as a guest', function (array $state): void {
    signInCustomer($this, makeCustomer($state));

    expect(viewerGroupAfterRequest()->code)->toBe('guest');
})->with([
    'blocked' => [['is_blocked' => true]],
    'unconfirmed' => [['email_verified_at' => null]],
]);

it('lets the staff preview win over a customer session (CG-18)', function (): void {
    $group = CustomerGroup::factory()->create(['name' => 'Для просмотра']);
    signInCustomer($this, makeCustomer());
    previewAs($this, $group);

    expect(viewerGroupAfterRequest()->id)->toBe($group->id)
        ->and(app(ViewerContext::class)->customer())->toBeNull();
});

it('shows a category to a customer whose group sees it and hides it from the guest (CG-13)', function (): void {
    configureGuest([], CategoryAccessMode::AllExcept, [$this->secret]);
    $wholesale = CustomerGroup::factory()->create(['name' => 'Свои']);

    $this->get('/catalog/'.$this->secret->slug)->assertNotFound();
    $this->get('/product/'.$this->hidden->slug)->assertNotFound();
    $this->get('/catalog')->assertDontSee('Скрытый чайник');

    signInCustomer($this, makeCustomer(['customer_group_id' => $wholesale->id]));

    $this->get('/catalog/'.$this->secret->slug)->assertOk()->assertSee('Скрытый чайник');
    $this->get('/product/'.$this->hidden->slug)->assertOk();
    $this->getJson('/search/suggest?q='.rawurlencode('чайник'))->assertJsonFragment(['name' => 'Скрытый чайник']);
});

it('hides a category from a customer whose group does not see it (CG-13)', function (): void {
    $restricted = CustomerGroup::factory()->categoryAccess(CategoryAccessMode::AllExcept, [$this->secret])->create();
    signInCustomer($this, makeCustomer(['customer_group_id' => $restricted->id]));

    $this->get('/product/'.$this->hidden->slug)->assertNotFound();
    $this->get('/product/'.$this->visible->slug)->assertOk();
});

it('marks a page that the customer sees but the guest does not as not indexable and private (CG-15)', function (): void {
    configureGuest([], CategoryAccessMode::AllExcept, [$this->secret]);
    signInCustomer($this, makeCustomer());

    $response = $this->get('/product/'.$this->hidden->slug)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->get('Cache-Control'))->toContain('private');
});

it('never lets a shared cache keep a page shown to a signed-in customer (26.3)', function (): void {
    signInCustomer($this, makeCustomer());

    $response = $this->get('/catalog')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    $response->assertHeaderMissing('X-Robots-Tag');
});

it('keeps prices out of the page for the guest in the «login» mode and links to the real sign-in (CG-06)', function (): void {
    configureGuest(['price_display' => PriceDisplayMode::Login]);

    $this->get('/product/'.$this->visible->slug)
        ->assertOk()
        ->assertSee('Войдите, чтобы увидеть цену')
        ->assertSee('href="'.route('customer.login').'"', false)
        ->assertDontSee(Money::format(100000));

    // The link leads to a working sign-in page.
    $this->get(route('customer.login'))->assertOk()->assertSee('Вход в личный кабинет');
});

it('shows the price to the signed-in customer of a group that sees prices, while the guest does not (CG-06)', function (): void {
    configureGuest(['price_display' => PriceDisplayMode::Login]);
    signInCustomer($this, makeCustomer());

    $this->get('/product/'.$this->visible->slug)
        ->assertOk()
        ->assertSee(Money::format(100000))
        ->assertDontSee('Войдите, чтобы увидеть цену');
});

it('puts the sign-in price mode into the group form (CG-06)', function (): void {
    expect(PriceDisplayMode::options())->toHaveKey('login');
});
