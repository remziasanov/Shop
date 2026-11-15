<?php

declare(strict_types=1);

use App\Exceptions\SalesException;
use App\Filament\Resources\CustomerGroups\Pages\ListCustomerGroups;
use App\Livewire\Storefront\CartPage;
use App\Models\Cart;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartService;
use App\Services\Orders\InquiryData;
use App\Services\Orders\OrderService;
use App\Services\Orders\ReservationData;
use App\Services\Users\RoleService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * CG-18: the staff preview of the storefront «как группа» — POST only, the right is re-checked on every request and
 * nothing is bought in it.
 */
beforeEach(function (): void {
    $this->withoutVite();
    $this->group = CustomerGroup::factory()->create(['name' => 'Оптовики превью']);
});

it('enters and leaves the preview with POST routes only', function (): void {
    foreach (['viewer.preview', 'viewer.preview.exit'] as $name) {
        $methods = Route::getRoutes()->getByName($name)?->methods() ?? [];

        expect($methods)->toContain('POST')->not->toContain('GET');
    }
});

it('starts the preview from the groups table through a Livewire action (CSRF protected)', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListCustomerGroups::class)
        ->callTableAction('preview', $this->group)
        ->assertRedirect(route('home'));

    expect(session(ViewerContext::PREVIEW_KEY))->toBe($this->group->id);
});

it('refuses the Livewire preview action to staff without customer_groups.view', function (): void {
    $this->actingAs(staffWith(['customer_groups.view', 'customer_groups.update']));
    $table = Livewire::test(ListCustomerGroups::class);

    $this->actingAs(staffWith(['pages.view']));

    try {
        $table->callTableAction('preview', $this->group);
    } catch (Throwable) {
        // refused (403 or a hidden component)
    }

    expect(session(ViewerContext::PREVIEW_KEY))->toBeNull();
});

it('ends the preview as soon as the role loses customer_groups.view (CG-18)', function (): void {
    $user = staffWith(['customer_groups.view']);
    $this->actingAs($user)->withSession([ViewerContext::PREVIEW_KEY => $this->group->id]);

    $this->get('/')->assertOk()->assertSee('Оптовики превью');

    app(RoleService::class)->syncPermissionsUnchecked(Role::query()->findOrFail($user->role_id), ['pages.view']);

    $this->get('/')->assertOk()->assertDontSee('Оптовики превью');
    expect(app(ViewerContext::class)->isPreview())->toBeFalse()
        ->and(app(ViewerContext::class)->group()->code)->toBe('guest');
});

it('ends the preview of a deactivated staff account', function (): void {
    $user = staffWith(['customer_groups.view']);
    $this->actingAs($user)->withSession([ViewerContext::PREVIEW_KEY => $this->group->id]);
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user->fresh())->get('/')->assertDontSee('Оптовики превью');
});

it('shows the exit button as a CSRF form', function (): void {
    previewAs($this, $this->group);

    $this->get('/')
        ->assertSee(route('viewer.preview.exit'), false)
        ->assertSee('name="_token"', false);
});

describe('nothing is bought in a preview', function (): void {
    beforeEach(function (): void {
        previewAs($this, $this->group);
        $this->product = sellable();
    });

    it('refuses the cart add form with a friendly message', function (): void {
        $this->from('/')->post(route('cart.add'), ['product_id' => $this->product->id])
            ->assertRedirect('/')
            ->assertSessionHas('cart_error', __('customer_groups.storefront.preview_blocked'));

        $this->postJson(route('cart.add'), ['product_id' => $this->product->id])
            ->assertStatus(422)
            ->assertJson(['message' => __('customer_groups.storefront.preview_blocked')]);

        expect(Cart::query()->count())->toBe(0);
    });

    it('sends the checkout page back to the cart with the message', function (): void {
        $this->get(route('checkout'))
            ->assertRedirect(route('cart'))
            ->assertSessionHas('cart_error', __('customer_groups.storefront.preview_blocked'));
    });

    it('refuses cart, reservation and inquiry in the services behind the storefront components', function (): void {
        // Livewire tests run without a session, so the preview request is built by hand.
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());
        app()->instance('request', $request);

        $message = __('customer_groups.storefront.preview_blocked');
        $contact = ['name' => 'Иван Тестов', 'phone' => '8 (900) 123-45-67', 'email' => null, 'consent' => true, 'ip' => '127.0.0.1'];

        expect(fn () => app(CartService::class)->add(Cart::factory()->create(), $this->product))->toThrow(SalesException::class, $message)
            ->and(fn () => app(OrderService::class)->createInquiry($this->product, new InquiryData(...$contact, qty: 1, comment: null)))->toThrow(SalesException::class, $message)
            ->and(fn () => app(OrderService::class)->createReservation($this->product, new ReservationData(...$contact, qty: 1, desiredDate: null, comment: null)))->toThrow(SalesException::class, $message)
            ->and(Order::query()->count())->toBe(0);
    });

    it('keeps the cart rows intact: quantity change and removal are blocked like adding (CG-18)', function (): void {
        $cart = cartWith($this->product);
        $item = $cart->items()->firstOrFail();
        $message = __('customer_groups.storefront.preview_blocked');

        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());
        app()->instance('request', $request);

        $carts = app(CartService::class);

        expect(fn () => $carts->update($cart, $this->product->id, 5))->toThrow(SalesException::class, $message)
            ->and(fn () => $carts->update($cart, $this->product->id, 0))->toThrow(SalesException::class, $message)
            ->and(fn () => $carts->remove($cart, $this->product->id))->toThrow(SalesException::class, $message)
            ->and(fn () => $carts->reduceToAvailable($cart, [$item->id]))->toThrow(SalesException::class, $message)
            ->and($cart->items()->count())->toBe(1)
            ->and($cart->items()->firstOrFail()->qty)->toBe($item->qty);
    });

    it('shows the message in CartPage instead of failing, and leaves the rows alone', function (): void {
        $cart = cartWith($this->product);
        $qty = $cart->items()->firstOrFail()->qty;

        // Livewire tests run without a session, so the preview request is built by hand.
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());
        $request->cookies->set('cart', $cart->token);
        app()->instance('request', $request);

        $page = new CartPage;
        $page->updatedQtys('5', (string) $this->product->id);
        expect($page->getErrorBag()->first('cart'))->toBe(__('customer_groups.storefront.preview_blocked'));

        $page->resetErrorBag();
        $page->remove($this->product->id);
        expect($page->getErrorBag()->first('cart'))->toBe(__('customer_groups.storefront.preview_blocked'));

        expect($cart->items()->count())->toBe(1)->and($cart->items()->firstOrFail()->qty)->toBe($qty);
    });
});

it('protects the preview POST routes with the web group CSRF middleware and no exception (CG-18)', function (): void {
    $router = app('router');
    $except = new ReflectionMethod(PreventRequestForgery::class, 'inExceptArray');

    foreach (['viewer.preview' => route('viewer.preview', 1), 'viewer.preview.exit' => route('viewer.preview.exit')] as $name => $url) {
        $route = Route::getRoutes()->getByName($name);
        $stack = $router->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
        $request = Request::create($url, 'POST');

        expect($stack)->toContain(PreventRequestForgery::class)
            ->and($route->excludedMiddleware())->toBe([])
            ->and($except->invoke(app(PreventRequestForgery::class), $request))->toBeFalse();
    }
});
