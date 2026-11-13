<?php

declare(strict_types=1);

use App\Enums\PromoLinkTargetType;
use App\Enums\PromoScope;
use App\Enums\PromoType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\PromoLink;
use App\Models\PromoRedemption;
use App\Services\Access\ViewerContext;
use App\Services\Cart\CartMerger;
use App\Services\Cart\CartService;
use App\Services\Promo\PromoCartService;
use App\Services\Promo\PromoCodeNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('stores codes upper-cased and unique without regard to case (PM-01)', function (): void {
    PromoCode::factory()->create(['code' => '  summer-2026 ']);

    expect(PromoCode::query()->value('code'))->toBe('SUMMER-2026')
        ->and(fn () => PromoCode::factory()->create(['code' => 'Summer-2026']))->toThrow(UniqueConstraintViolationException::class)
        ->and(PromoCode::findByCode(' summer-2026 '))->not->toBeNull()
        ->and(PromoCode::findByCode('   '))->toBeNull();
});

it('accepts 4 to 40 Latin letters, digits and hyphens as a code (PM-01)', function (): void {
    foreach (['ABCD', 'spring-10', str_repeat('A', 40), 'A1-B2'] as $ok) {
        expect(PromoCodeNormalizer::isValid($ok))->toBeTrue($ok);
    }

    foreach (['ABC', str_repeat('A', 41), 'ВЕСНА10', 'SPRING 10', 'SPRING_10', 'A+B-C', ''] as $bad) {
        expect(PromoCodeNormalizer::isValid($bad))->toBeFalse($bad);
    }
});

it('casts the enums and keeps money as decimal strings (PM-02, PM-03, 22.5)', function (): void {
    $code = PromoCode::factory()->fixed('150.50')->create(['min_order_amount' => '3000.00']);

    expect($code->refresh())->type->toBe(PromoType::Fixed)->scope->toBe(PromoScope::All)->value->toBe('150.50')
        ->min_order_amount->toBe('3000.00')->include_subcategories->toBeTrue()->only_registered->toBeFalse()
        ->applies_to_discounted->toBeFalse()->stackable_with_promotions->toBeFalse()->usage_count->toBe(0)
        ->and($code->minOrderKopecks())->toBe(300_000);

    $link = PromoLink::factory()->create();

    expect($link->target_type)->toBe(PromoLinkTargetType::Url)->and($link->utm())->toBe([]);
});

it('cannot delete a code that has redemptions but can delete an unused one (PM-19)', function (): void {
    $unused = PromoCode::factory()->create();
    $unused->delete();

    expect(PromoCode::query()->count())->toBe(0);

    $used = PromoCode::factory()->create();
    PromoRedemption::factory()->create(['promo_code_id' => $used->id]);

    expect(fn () => $used->delete())->toThrow(LogicException::class)
        // The database refuses a raw delete too (FK restrict).
        ->and(PromoCode::query()->count())->toBe(1);
});

it('keeps the order snapshot when the code row goes away (PM-18, null on delete)', function (): void {
    $code = PromoCode::factory()->create(['code' => 'GONE10']);
    $order = Order::factory()->create(['promo_code_id' => $code->id, 'promo_code' => 'GONE10', 'promo_discount' => '50.00']);

    $code->delete();

    expect($order->refresh())->promo_code->toBe('GONE10')->promo_code_id->toBeNull()->promo_discount->toBe('50.00');
});

it('exposes promo_old_price of a cart line only for the shop own old price (PM-05)', function (): void {
    sales(['stock_tracking' => false]);
    $sale = sellable(['base_price' => '1000.00', 'compare_at_price' => '1250.00']);
    $plain = sellable(['base_price' => '1000.00']);
    $cart = promoCart($sale);
    CartItem::factory()->forProduct($plain)->create(['cart_id' => $cart->id]);

    $lines = app(CartService::class)->summary($cart)->lines;

    expect($lines[0]->promoOldPrice)->toBe(125_000)->and($lines[1]->promoOldPrice)->toBeNull();
});

it('does not take the struck-through retail price of a group as an old price (PM-05)', function (): void {
    sales(['stock_tracking' => false]);
    $group = CustomerGroup::factory()->discount(10)->showRetailPrice()->create();
    $product = sellable(['base_price' => '1000.00']);
    $tier = gpTier($product, $group, 5, '700.00');
    app(ViewerContext::class)->actingAs($group);

    $cart = promoCart($product, 1);
    $groupLine = app(CartService::class)->summary($cart)->lines[0];
    $cart->items()->first()->update(['qty' => 5]);
    $tierLine = app(CartService::class)->summary($cart->refresh())->lines[0];

    // 900 for the group with the retail price 1 000 struck through, and the tier price 700: neither is a promotion.
    expect($groupLine->currentPrice)->toBe(90_000)->and($groupLine->promoOldPrice)->toBeNull()
        ->and($tierLine->currentPrice)->toBe(70_000)->and($tierLine->promoOldPrice)->toBeNull();
});

it('keeps the group retail strike-through out of the best-price base of a code (PM-05, PM-06)', function (): void {
    sales(['stock_tracking' => false]);
    $group = CustomerGroup::factory()->discount(10)->showRetailPrice()->create();
    $product = sellable(['base_price' => '1000.00']);
    PromoCode::factory()->percent(10)->forGroups($group)->create(['code' => 'GRP10']);
    app(ViewerContext::class)->actingAs($group);

    $cart = promoCart($product, 1);
    $summary = app(CartService::class)->summary($cart);
    $service = app(PromoCartService::class);
    $result = $service->apply($cart, $summary, 'grp10')->result;

    // The item is NOT «on promotion»: the code takes 10 % of the group price 900 even though the code flags are off.
    expect($result->isApplied())->toBeTrue()->and($result->discount)->toBe(9_000)->and($cart->refresh()->promo_code)->toBe('GRP10');
});

it('runs the cart promo flow: apply, replace, remove, auto-apply rules (PM-07, PM-13, PM-23)', function (): void {
    sales(['stock_tracking' => false]);
    $product = sellable(['base_price' => '1000.00']);
    PromoCode::factory()->percent(10)->create(['code' => 'ONE10']);
    PromoCode::factory()->percent(20)->create(['code' => 'TWO20']);
    $cart = promoCart($product, 2);
    $service = app(PromoCartService::class);
    $summary = fn () => app(CartService::class)->summary($cart->refresh());

    expect($service->evaluate($cart, $summary()))->toBeNull();

    $first = $service->apply($cart, $summary(), 'one10');

    expect($first->result->discount)->toBe(20_000)->and($first->replaced)->toBeNull()->and($cart->refresh()->promo_applied_manually)->toBeTrue();

    $second = $service->apply($cart, $summary(), 'TWO20');

    expect($second->replaced)->toBe('ONE10')->and($cart->refresh()->promo_code)->toBe('TWO20')
        ->and($service->evaluate($cart, $summary())->discount)->toBe(40_000);

    // An unknown code leaves the stored one alone.
    $bad = $service->apply($cart, $summary(), 'NOPE');

    expect($bad->result->message)->toBe('Промокод не найден')->and($cart->refresh()->promo_code)->toBe('TWO20');

    $service->remove($cart);

    expect($cart->refresh()->promo_code)->toBeNull();

    // The customer removed the code by hand: the cookie code is not applied again.
    expect($service->autoApply($cart, $summary(), 'ONE10'))->toBeNull()->and($cart->refresh()->promo_code)->toBeNull();

    // A fresh cart takes the cookie code; a cart that has a code keeps it.
    $fresh = promoCart($product, 1);
    $auto = $service->autoApply($fresh, app(CartService::class)->summary($fresh), 'one10');

    expect($auto->isApplied())->toBeTrue()->and($fresh->refresh()->promo_code)->toBe('ONE10')->and($fresh->promo_applied_manually)->toBeFalse()
        ->and($service->autoApply($fresh, app(CartService::class)->summary($fresh), 'TWO20'))->toBeNull();

    // A code that does not apply now is only reported, not stored (PM-23).
    PromoCode::factory()->create(['code' => 'BIG', 'min_order_amount' => '99999.00']);
    $other = promoCart($product, 1);
    $hint = $service->autoApply($other, app(CartService::class)->summary($other), 'BIG');

    expect($hint->isApplied())->toBeFalse()->and($hint->message)->toContain('Минимальная сумма заказа')->and($other->refresh()->promo_code)->toBeNull();
});

it('creates and drops the promo tables and columns reversibly (22.5)', function (): void {
    $tables = ['promo_codes', 'promo_code_groups', 'promo_code_targets', 'promo_code_batches', 'promo_redemptions', 'promo_links', 'promo_link_stats'];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    expect(Schema::hasColumns('carts', ['promo_code', 'promo_applied_manually']))->toBeTrue()
        ->and(Schema::hasColumns('orders', ['promo_code_id', 'promo_code', 'promo_discount', 'manual_discount', 'promo_link_id']))->toBeTrue()
        ->and(Schema::hasColumn('order_items', 'promo_discount'))->toBeTrue();

    $path = 'database/migrations';
    // Roll back exactly the two promo migrations and run them again.
    Artisan::call('migrate:rollback', ['--path' => $path.'/2026_11_17_100002_add_promo_columns_to_carts_orders_and_items.php', '--force' => true]);

    expect(Schema::hasColumn('orders', 'promo_code_id'))->toBeFalse()->and(Schema::hasColumn('carts', 'promo_code'))->toBeFalse()
        ->and(Schema::hasColumn('order_items', 'promo_discount'))->toBeFalse();

    Artisan::call('migrate:rollback', ['--path' => $path.'/2026_11_17_100001_create_promo_tables.php', '--force' => true]);

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse($table);
    }

    Artisan::call('migrate', ['--force' => true]);

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue($table);
    }

    expect(Schema::hasColumn('orders', 'promo_link_id'))->toBeTrue();
})->skip(fn () => DB::getDriverName() === 'mysql', 'DDL commits the transaction of the test on MySQL; the MySQL rollback is checked by hand');

it('keeps the promo code of a guest cart when it is merged into the customer cart (22.7, CA-15)', function (): void {
    sales(['stock_tracking' => false]);
    $customer = Customer::factory()->create();
    $product = sellable();

    // Case 1: the customer has no cart yet, the guest cart becomes theirs with its code.
    $guest = promoCart($product, 1, 'MERGE10');
    app(CartMerger::class)->merge($customer, $guest->token);

    expect(Cart::query()->where('customer_id', $customer->id)->value('promo_code'))->toBe('MERGE10');

    // Case 2: the customer cart has no code, the guest cart has one.
    $other = Customer::factory()->create();
    Cart::factory()->create(['token' => (string) Str::uuid()])->forceFill(['customer_id' => $other->id])->save();
    $guestWithCode = promoCart($product, 1, 'MERGE20');
    app(CartMerger::class)->merge($other, $guestWithCode->token);

    expect(Cart::query()->where('customer_id', $other->id)->value('promo_code'))->toBe('MERGE20');

    // Case 3: the customer's own code wins.
    $guestAgain = promoCart($product, 1, 'GUESTONE');
    app(CartMerger::class)->merge($other, $guestAgain->token);

    expect(Cart::query()->where('customer_id', $other->id)->value('promo_code'))->toBe('MERGE20');
});
