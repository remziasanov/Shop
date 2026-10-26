<?php

declare(strict_types=1);

use App\Models\Cart;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Livewire test of a storefront component with the visitor's cart cookie.
 */
function livewireWithCart(string $component, ?Cart $cart = null, array $params = []): Testable
{
    if ($cart !== null) {
        Livewire::withCookie('cart', $cart->token);
    }

    return Livewire::test($component, $params);
}

function resetFormGuard(): void
{
    RateLimiter::clear('sales-form:127.0.0.1');
}

/**
 * Valid checkout input for the pickup method (set on a Testable).
 */
function fillCheckout(Testable $component, string $delivery = 'samovyvoz', array $overrides = []): Testable
{
    $values = array_merge([
        'name' => 'Иван Тестов',
        'phone' => '8 (900) 123-45-67',
        'email' => 'ivan@example.com',
        'delivery' => $delivery,
        'address' => $delivery === 'samovyvoz' ? '' : 'г. Тестоград, ул. Примерная, 1',
        'payment' => 'nalicnymi-pri-polucenii',
        'comment' => 'Позвоните заранее',
        'consent' => true,
    ], $overrides);

    foreach ($values as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}
