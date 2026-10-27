<?php

declare(strict_types=1);

use App\Models\Product;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    generalSettings(['shop_open' => false, 'closed_message' => 'Вернёмся в понедельник']);
});

it('answers 503 with the message and Retry-After for guests (SF-05)', function () {
    $product = Product::factory()->create();

    foreach (['/', '/catalog', route('product.show', $product->slug), '/cart', '/sitemap.xml'] as $url) {
        $this->get($url)
            ->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertSee('Вернёмся в понедельник');
    }

    $this->post(route('cart.add'), ['product_id' => $product->id])->assertStatus(503);
});

it('shows the default text when the message is empty', function () {
    generalSettings(['closed_message' => null]);

    $this->get('/')->assertStatus(503)->assertSee('Магазин временно закрыт');
});

it('shows the storefront normally to a signed-in administrator or manager (SF-05)', function (string $role) {
    $this->actingAs(User::factory()->{$role}()->create());

    $this->get('/')->assertOk()->assertDontSee('Вернёмся в понедельник');
    $this->get('/catalog')->assertOk();
})->with(['admin', 'manager']);

it('keeps the shop closed for an inactive staff account', function () {
    $this->actingAs(User::factory()->admin()->inactive()->create());

    $this->get('/')->assertStatus(503);
});

it('keeps the admin panel and health check working (SF-05)', function () {
    $admin = config('shop.admin_path');

    $this->get('/'.$admin.'/login')->assertOk();
    $this->get('/up')->assertOk();

    $this->actingAs(User::factory()->admin()->create())->get('/'.$admin)->assertSuccessful();
});

it('keeps the normal robots.txt rules while closed, pages answer 503 instead (SF-05, D-056)', function () {
    $text = $this->get('/robots.txt')->assertOk()->getContent();

    expect($text)->not->toMatch('/^Disallow:\s*\/\s*$/m')->and($text)->toContain('Disallow: /cart')->toContain('Sitemap:');
});

it('is open by default', function () {
    generalSettings(['shop_open' => true]);

    $this->get('/')->assertOk();
});
