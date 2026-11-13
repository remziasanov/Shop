<?php

declare(strict_types=1);

use App\Enums\PromoFailure;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\PromoCode;
use App\Models\PromoRedemption;
use App\Services\Promo\PromoCustomerHash;
use App\Services\Promo\PromoLine;
use App\Services\Promo\PromoRedemptionService;
use App\Services\Promo\PromoViewer;
use App\Support\Money;
use Illuminate\Support\Carbon;

it('gives a percent discount per line and in total (PM-02, PM-08)', function (): void {
    $code = PromoCode::factory()->percent(10)->create();
    $result = evaluatePromo([pline(100_000, 2, key: 1), pline(50_050, 1, product: 2, key: 2)], $code);

    // 20 000 and 5 005 (50 050 * 10 % = 5 005).
    expect($result->isApplied())->toBeTrue()
        ->and($result->lineDiscounts)->toBe([1 => 20_000, 2 => 5_005])
        ->and($result->discount)->toBe(25_005)->and($result->freeDelivery)->toBeFalse();
});

it('rounds a percent share half up to the kopeck', function (): void {
    // 333 kopecks * 10.50 % = 34.965 -> 35; 1 kopeck * 50 % = 0.5 -> 1; 1 kopeck * 49.5 % = 0.495 -> 0.
    expect(evaluatePromo([pline(333)], PromoCode::factory()->percent('10.5')->create())->discount)->toBe(35)
        ->and(evaluatePromo([pline(1)], PromoCode::factory()->percent(50)->create())->discount)->toBe(1)
        ->and(evaluatePromo([pline(1)], PromoCode::factory()->percent('49.5')->create())->discount)->toBe(0)
        ->and(evaluatePromo([pline(1999)], PromoCode::factory()->percent(100)->create())->discount)->toBe(1999);
});

it('never takes more than the line sums for a fixed discount (PM-02)', function (): void {
    $code = PromoCode::factory()->fixed('5000.00')->create();
    $result = evaluatePromo([pline(30_000), pline(20_000, product: 2)], $code);

    expect($result->discount)->toBe(50_000)->and(array_sum($result->lineDiscounts))->toBe(50_000);
});

it('splits a fixed discount by the largest remainder so the shares add up exactly (PM-08)', function (): void {
    // 1.00 RUB over three equal 1.00 RUB lines: 33 + 33 + 33 = 99, the extra kopeck goes to the first line.
    $result = evaluatePromo([pline(100, key: 1), pline(100, product: 2, key: 2), pline(100, product: 3, key: 3)], PromoCode::factory()->fixed('1.00')->create());

    expect($result->lineDiscounts)->toBe([1 => 34, 2 => 33, 3 => 33])->and($result->discount)->toBe(100);

    // Uneven bases: 100.00 over 333.33 / 666.67 / 1 000.00.
    $lines = [pline(33_333, key: 1), pline(66_667, product: 2, key: 2), pline(100_000, product: 3, key: 3)];
    $result = evaluatePromo($lines, PromoCode::factory()->fixed('100.00')->create());

    expect(array_sum($result->lineDiscounts))->toBe(10_000)->and($result->discount)->toBe(10_000);
    // Proportional within one kopeck of the exact share.
    foreach ($lines as $line) {
        $exact = 10_000 * $line->unitPrice / 200_000;
        expect(abs($result->lineDiscounts[$line->key] - $exact))->toBeLessThan(1.0);
    }
});

it('keeps the fixed discount sum equal for many lines and quantities', function (): void {
    $lines = [];

    for ($i = 1; $i <= 17; $i++) {
        $lines[] = pline(100 + $i * 37, $i % 4 + 1, product: $i, key: $i);
    }

    $result = evaluatePromo($lines, PromoCode::factory()->fixed('123.45')->create());

    expect(array_sum($result->lineDiscounts))->toBe(12_345)->and($result->discount)->toBe(12_345);
});

it('sets the free delivery flag without a money discount (PM-02)', function (): void {
    $result = evaluatePromo([pline(100_000)], PromoCode::factory()->freeDelivery()->create());

    expect($result->isApplied())->toBeTrue()->and($result->freeDelivery)->toBeTrue()->and($result->discount)->toBe(0);
});

it('answers «Промокод не найден» for an unknown or an inactive code and nothing else (PM-09)', function (): void {
    PromoCode::factory()->inactive()->create(['code' => 'SLEEPING']);

    $unknown = evaluatePromo([pline(100_000)], 'NOSUCH');
    $inactive = evaluatePromo([pline(100_000)], 'sleeping');

    expect($unknown->failure)->toBe(PromoFailure::NotFound)->and($unknown->message)->toBe('Промокод не найден')
        ->and($inactive->failure)->toBe(PromoFailure::Inactive)->and($inactive->message)->toBe('Промокод не найден')
        ->and(evaluatePromo([pline(100_000)], '   ')->failure)->toBe(PromoFailure::NotFound);
});

it('finds the code ignoring case and surrounding spaces (PM-01)', function (): void {
    PromoCode::factory()->create(['code' => 'spring10']);

    expect(PromoCode::query()->first()->code)->toBe('SPRING10')
        ->and(evaluatePromo([pline(100_000)], '  Spring10 ')->isApplied())->toBeTrue();
});

it('refuses outside the period with the date (PM-04, PM-09)', function (): void {
    $code = PromoCode::factory()->create(['starts_at' => '2026-03-01 00:00:00', 'ends_at' => '2026-03-31 23:59:59']);

    $before = evaluatePromo([pline(100_000)], $code, null, Carbon::parse('2026-02-20 12:00:00'));
    $inside = evaluatePromo([pline(100_000)], $code, null, Carbon::parse('2026-03-15 12:00:00'));
    $after = evaluatePromo([pline(100_000)], $code, null, Carbon::parse('2026-04-02 12:00:00'));

    expect($before->failure)->toBe(PromoFailure::NotStarted)->and($before->message)->toBe('Промокод действует с 01.03')
        ->and($inside->isApplied())->toBeTrue()
        ->and($after->failure)->toBe(PromoFailure::Expired)->and($after->message)->toBe('Срок действия промокода истёк');
});

it('checks the customer group (PM-04)', function (): void {
    $wholesale = CustomerGroup::factory()->create(['name' => 'Опт']);
    $code = PromoCode::factory()->create();

    $guest = evaluatePromo([pline(100_000)], $code, guestPromoViewer());
    $retail = evaluatePromo([pline(100_000)], $code, retailPromoViewer());
    $opt = evaluatePromo([pline(100_000)], $code, new PromoViewer($wholesale->id));

    expect($guest->isApplied())->toBeTrue()->and($retail->isApplied())->toBeTrue()
        ->and($opt->failure)->toBe(PromoFailure::GroupNotAllowed);

    $only = PromoCode::factory()->forGroups($wholesale)->create();

    expect(evaluatePromo([pline(100_000)], $only, new PromoViewer($wholesale->id))->isApplied())->toBeTrue()
        ->and(evaluatePromo([pline(100_000)], $only, guestPromoViewer())->failure)->toBe(PromoFailure::GroupNotAllowed);
});

it('gives a new code the groups «Гость» and «Розница» by default (PM-04)', function (): void {
    $code = PromoCode::factory()->create();

    expect($code->groups()->pluck('code')->all())->toEqualCanonicalizing(['guest', 'retail']);
});

it('needs a signed-in customer with a verified e-mail for «только для зарегистрированных» (PM-04)', function (): void {
    $code = PromoCode::factory()->create(['only_registered' => true]);
    $customer = Customer::factory()->create();

    $guest = evaluatePromo([pline(100_000)], $code, guestPromoViewer());
    $unverified = evaluatePromo([pline(100_000)], $code, retailPromoViewer($customer, verified: false));
    $verified = evaluatePromo([pline(100_000)], $code, retailPromoViewer($customer));

    expect($guest->failure)->toBe(PromoFailure::OnlyRegistered)
        ->and($guest->message)->toBe('Промокод только для зарегистрированных покупателей')
        ->and($unverified->failure)->toBe(PromoFailure::OnlyRegistered)
        ->and($verified->isApplied())->toBeTrue();
});

it('counts the minimum order on the lines before the promo code and says how much (PM-04, PM-09)', function (): void {
    $code = PromoCode::factory()->create(['min_order_amount' => '3000.00']);

    $short = evaluatePromo([pline(100_000, 2)], $code);
    $enough = evaluatePromo([pline(100_000, 2), pline(100_000, 1, product: 2)], $code);

    expect($short->failure)->toBe(PromoFailure::MinOrder)
        ->and($short->message)->toBe('Минимальная сумма заказа для кода — '.Money::format(300_000))
        ->and($enough->isApplied())->toBeTrue();
});

it('counts the minimum on the promotion price, not on the old price (PM-04)', function (): void {
    $code = PromoCode::factory()->create(['min_order_amount' => '3000.00', 'applies_to_discounted' => true]);

    // 2 x 1 200 = 2 400 after the promotion although the old price sum is 3 000.
    expect(evaluatePromo([pline(120_000, 2, old: 150_000)], $code)->failure)->toBe(PromoFailure::MinOrder);
});

it('refuses an exhausted code (PM-04, PM-09)', function (): void {
    $code = PromoCode::factory()->create(['usage_limit' => 2, 'usage_count' => 2]);

    expect(evaluatePromo([pline(100_000)], $code)->failure)->toBe(PromoFailure::Exhausted)
        ->and(evaluatePromo([pline(100_000)], $code)->message)->toBe('Промокод исчерпан');

    $code->forceFill(['usage_count' => 1])->save();

    expect(evaluatePromo([pline(100_000)], $code->refresh())->isApplied())->toBeTrue();
});

it('limits a signed-in customer by id and ignores released redemptions (PM-10)', function (): void {
    $code = PromoCode::factory()->create(['per_customer_limit' => 1]);
    $customer = Customer::factory()->create();
    $other = Customer::factory()->create();
    $viewer = retailPromoViewer($customer);

    expect(evaluatePromo([pline(100_000)], $code, $viewer)->isApplied())->toBeTrue();

    $redemption = PromoRedemption::factory()->create(['promo_code_id' => $code->id, 'customer_id' => $customer->id]);

    expect(evaluatePromo([pline(100_000)], $code, $viewer)->failure)->toBe(PromoFailure::PerCustomerLimit)
        ->and(evaluatePromo([pline(100_000)], $code, retailPromoViewer($other))->isApplied())->toBeTrue();

    $redemption->update(['released_at' => now()]);

    expect(evaluatePromo([pline(100_000)], $code, $viewer)->isApplied())->toBeTrue();
});

it('limits a guest by the HMAC of e-mail and phone (PM-10)', function (): void {
    $code = PromoCode::factory()->create(['per_customer_limit' => 1]);
    $hash = PromoCustomerHash::make('Ivan@Example.com', '+79001234567');

    PromoRedemption::factory()->create(['promo_code_id' => $code->id, 'customer_hash' => $hash]);

    $same = guestPromoViewer('ivan@example.com ', '+79001234567');
    $otherPhone = guestPromoViewer('ivan@example.com', '+79001234568');

    expect(evaluatePromo([pline(100_000)], $code, $same)->failure)->toBe(PromoFailure::PerCustomerLimit)
        ->and(evaluatePromo([pline(100_000)], $code, $otherPhone)->isApplied())->toBeTrue()
        // The contact is unknown in the cart: the limit is checked at checkout.
        ->and(evaluatePromo([pline(100_000)], $code, guestPromoViewer())->isApplied())->toBeTrue();
});

it('hashes with the application key, not with a plain digest (PM-10)', function (): void {
    $hash = PromoCustomerHash::make('a@example.com', '+79001234567');

    expect($hash)->toBe(hash_hmac('sha256', 'a@example.com|+79001234567', (string) config('app.key')))
        ->and($hash)->not->toBe(hash('sha256', 'a@example.com|+79001234567'))
        ->and(strlen((string) $hash))->toBe(64)
        ->and(PromoCustomerHash::make(null, null))->toBeNull();
});

it('limits the scope to categories including subcategories, and to products and brands (PM-03)', function (): void {
    $shoes = Category::factory()->create();
    $sneakers = Category::factory()->childOf($shoes)->create();
    $bags = Category::factory()->create();

    $withSub = PromoCode::factory()->forCategories([$shoes])->create();
    $exact = PromoCode::factory()->forCategories([$shoes], withSubcategories: false)->create();
    $lines = [
        pline(100_000, category: $sneakers->id, key: 1),
        pline(100_000, category: $bags->id, product: 2, key: 2),
        pline(100_000, category: $shoes->id, product: 3, key: 3),
    ];

    expect(evaluatePromo($lines, $withSub)->lineDiscounts)->toBe([1 => 10_000, 3 => 10_000])
        ->and(evaluatePromo($lines, $exact)->lineDiscounts)->toBe([3 => 10_000])
        ->and(evaluatePromo([pline(100_000, category: $bags->id)], $withSub)->failure)->toBe(PromoFailure::NoEligibleItems);

    $byProduct = PromoCode::factory()->forProducts([sellable(['name' => 'A'])])->create();
    $target = $byProduct->targets()->first()->target_id;

    expect(evaluatePromo([pline(100_000, product: $target, key: 7), pline(100_000, product: 99, key: 8)], $byProduct)->lineDiscounts)->toBe([7 => 10_000]);

    $byBrand = PromoCode::factory()->forBrands(['Nike'])->create();

    expect(evaluatePromo([pline(100_000, brand: ' nike ', key: 1), pline(100_000, brand: 'Adidas', product: 2, key: 2), pline(100_000, brand: null, product: 3, key: 3)], $byBrand)->lineDiscounts)->toBe([1 => 10_000]);
});

it('treats every variant of a card as a hit for a product scope (PM-03)', function (): void {
    $card = sellable();
    $code = PromoCode::factory()->forProducts([$card])->create();
    $lines = [
        new PromoLine('a', $card->id, 11, $card->category_id, null, 1, 100_000, null),
        new PromoLine('b', $card->id, 12, $card->category_id, null, 1, 50_000, null),
    ];

    expect(evaluatePromo($lines, $code)->lineDiscounts)->toBe(['a' => 10_000, 'b' => 5_000]);
});

it('requires an eligible item for free delivery (PM-03)', function (): void {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    $code = PromoCode::factory()->freeDelivery()->forCategories([$category])->create();

    expect(evaluatePromo([pline(100_000, category: $category->id)], $code)->freeDelivery)->toBeTrue()
        ->and(evaluatePromo([pline(100_000, category: $other->id)], $code)->failure)->toBe(PromoFailure::NoEligibleItems);
});

// PM-05 / PM-06: a line on promotion costs 800 (old price 1 000), a regular one 1 000.
it('skips lines on promotion unless the code applies to them (PM-05, flag off)', function (): void {
    $code = PromoCode::factory()->percent(10)->create(['applies_to_discounted' => false]);
    $result = evaluatePromo([pline(80_000, old: 100_000, key: 1), pline(100_000, product: 2, key: 2)], $code);

    expect($result->lineDiscounts)->toBe([2 => 10_000]);

    expect(evaluatePromo([pline(80_000, old: 100_000)], $code)->failure)->toBe(PromoFailure::NoEligibleItems);
});

it('discounts the reduced price of a promotion line when the code stacks (PM-05 on, PM-06 on)', function (): void {
    $code = PromoCode::factory()->percent(10)->create(['applies_to_discounted' => true, 'stackable_with_promotions' => true]);
    $result = evaluatePromo([pline(80_000, 2, old: 100_000, key: 1), pline(100_000, product: 2, key: 2)], $code);

    // 10 % of 1 600 and of 1 000.
    expect($result->lineDiscounts)->toBe([1 => 16_000, 2 => 10_000]);
});

it('gives the better of the promotion and the code price when the code does not stack (PM-06 off)', function (): void {
    $code = PromoCode::factory()->percent(30)->create(['applies_to_discounted' => true, 'stackable_with_promotions' => false]);

    // Old 1 000, promotion 800, code price 700: the code wins, discount against the paid price is 100.
    $wins = evaluatePromo([pline(80_000, old: 100_000, key: 1)], $code);
    // 10 %: code price 900 > promotion 800: the buyer keeps 800, no extra discount.
    $loses = evaluatePromo([pline(80_000, old: 100_000, key: 1), pline(100_000, product: 2, key: 2)], PromoCode::factory()->percent(10)->create(['applies_to_discounted' => true]));

    expect($wins->lineDiscounts)->toBe([1 => 10_000])->and($loses->lineDiscounts[1])->toBe(0)->and($loses->discount)->toBe(10_000);
});

it('refuses a percent / fixed code that takes nothing off, with a clear message (PM-05, PM-06)', function (): void {
    $percent = PromoCode::factory()->percent(10)->create(['applies_to_discounted' => true, 'stackable_with_promotions' => false]);
    $fixed = PromoCode::factory()->fixed('50.00')->create(['applies_to_discounted' => true, 'stackable_with_promotions' => false]);
    $lines = [pline(80_000, old: 100_000, key: 1)];

    foreach ([$percent, $fixed] as $code) {
        $result = evaluatePromo($lines, $code);

        expect($result->isApplied())->toBeFalse()->and($result->failure)->toBe(PromoFailure::NoDiscount)
            ->and($result->message)->toBe('Промокод не даёт дополнительной скидки на товары в корзине');
    }
});

it('does not burn a use on a code with a zero discount, but free delivery is unaffected (PM-11)', function (): void {
    $code = PromoCode::factory()->percent(10)->create(['applies_to_discounted' => true, 'usage_limit' => 5]);
    $free = PromoCode::factory()->freeDelivery()->create(['usage_limit' => 5]);
    $lines = [pline(80_000, old: 100_000, key: 1)];
    $redemptions = app(PromoRedemptionService::class);

    $result = $redemptions->claim($code->code, $lines, guestPromoViewer());
    $freeResult = $redemptions->claim($free->code, [pline(100_000, key: 1)], guestPromoViewer());

    expect($result->failure)->toBe(PromoFailure::NoDiscount)->and($code->refresh()->usage_count)->toBe(0)
        ->and($freeResult->isApplied())->toBeTrue()->and($free->refresh()->usage_count)->toBe(1);
});

it('keeps a fixed discount smaller than its face value when the promotion price is better (PM-06)', function (): void {
    $code = PromoCode::factory()->fixed('100.00')->create(['applies_to_discounted' => true, 'stackable_with_promotions' => false]);

    // Base 1 000 - 100 = 900 vs promotion 800: no gain on the first line; the regular line takes its part.
    $result = evaluatePromo([pline(80_000, old: 100_000, key: 1), pline(100_000, product: 2, key: 2)], $code);

    // Fixed 100.00 split over the bases 1 000 and 1 000: 50 each; line 1 -> min(950, 800) = 800 -> 0; line 2 -> 50.
    expect($result->lineDiscounts)->toBe([1 => 0, 2 => 5_000])->and($result->discount)->toBe(5_000);
});

it('treats a line without an old price the same in all four flag combinations (PM-05, PM-06)', function (): void {
    foreach ([[false, false], [false, true], [true, false], [true, true]] as [$discounted, $stack]) {
        $code = PromoCode::factory()->percent(10)->create(['applies_to_discounted' => $discounted, 'stackable_with_promotions' => $stack]);

        expect(evaluatePromo([pline(100_000, key: 1)], $code)->lineDiscounts)->toBe([1 => 10_000]);
    }
});

it('never lets the discount go below zero or above the line (PM-02)', function (): void {
    $result = evaluatePromo([pline(100)], PromoCode::factory()->fixed('9999.00')->create());

    expect($result->discount)->toBe(100);
});
