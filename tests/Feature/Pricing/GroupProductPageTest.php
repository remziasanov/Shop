<?php

declare(strict_types=1);

use App\Enums\PriceDisplayMode;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Product;
use App\Services\Pricing\GroupPriceRecalculator;

beforeEach(function (): void {
    $this->withoutVite();
    sales(['stock_tracking' => false]);
    $this->group = CustomerGroup::factory()->discount(10)->create(['name' => 'Опт']);
    $this->product = Product::factory()->inCategory(Category::factory()->create())->create(['name' => 'Лампа', 'base_price' => '1000.00', 'unit' => 'шт', 'brand' => null]);
});

it('shows the tier table of the viewer group with the saving in percent (GP-10)', function (): void {
    gpTier($this->product, $this->group, 10, '800.00');
    gpTier($this->product, $this->group, 50, '600.00');
    gpSignInAs($this, $this->group);

    $response = $this->get(route('product.show', $this->product->slug))->assertOk();
    $html = $response->getContent();

    $response->assertSee('data-tier-table', false)
        ->assertSeeText('Цены при покупке оптом')
        ->assertSeeText('1+ шт')->assertSeeText("900\u{00A0}₽")        // 1 000 - 10 %
        ->assertSeeText('10+ шт')->assertSeeText("800\u{00A0}₽")->assertSeeText('−11 %')
        ->assertSeeText('50+ шт')->assertSeeText("600\u{00A0}₽")->assertSeeText('−33 %');

    // Alpine gets the group's tiers in kopecks: [min_qty, price] and the price at quantity 1.
    expect($html)->toContain("tierPrice(90000, JSON.parse('[[10,80000],[50,60000]]'))")
        ->and($html)->toContain('data-tier-total');
});

it('has no tier table for a group without tiers or for a guest (GP-10)', function (): void {
    $other = CustomerGroup::factory()->create();
    gpTier($this->product, $this->group, 10, '800.00');

    $this->get(route('product.show', $this->product->slug))->assertOk()->assertDontSee('data-tier-table', false)->assertDontSee('tierPrice(', false);

    gpSignInAs($this, $other);
    $this->get(route('product.show', $this->product->slug))->assertOk()->assertDontSee('data-tier-table', false)->assertDontSee('80000', false);
});

it('never puts the tiers of another group into the page (21.7)', function (): void {
    $other = CustomerGroup::factory()->create();
    gpTier($this->product, $other, 5, '654.32');
    gpTier($this->product, $this->group, 10, '800.00');
    gpSignInAs($this, $this->group);

    $html = $this->get(route('product.show', $this->product->slug))->assertOk()->getContent();

    // Random tokens (CSRF, asset hashes) may contain "654", so match the price forms only.
    expect($html)->not->toContain('654.32')->not->toContain('654,32')->not->toContain('65432')->not->toContain("654\u{00A0}₽")->and($html)->toContain('80000');
});

it('embeds the group price and tiers of every variant for the picker (GP-10)', function (): void {
    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard(['name' => 'Куртка'], $size);
    $small = variantOf($card, [$size->values[0]], ['base_price' => '1000.00']);
    $medium = variantOf($card, [$size->values[1]], ['base_price' => '2000.00']);
    gpTier($small, $this->group, 5, '700.00');
    gpTier($medium, $this->group, 1, '1500.00');
    gpTier($medium, $this->group, 20, '1200.00');
    app(GroupPriceRecalculator::class)->recalculate([$card->id]);

    // Guest: retail prices and no group data at all.
    $guest = gpVariantData($this->get(route('product.show', $card->slug))->assertOk()->getContent());
    expect(array_keys($guest['variants'][0]))->not->toContain('t')->and($guest['variants'][0]['k'])->toBe(100000);

    gpSignInAs($this, $this->group);
    $html = $this->get(route('product.show', $card->slug))->assertOk()->getContent();
    $data = gpVariantData($html);
    $byId = collect($data['variants'])->keyBy('id');

    expect($byId[$small->id]['k'])->toBe(90000)
        ->and($byId[$small->id]['t'])->toBe([[5, 70000]])
        ->and($byId[$small->id])->not->toHaveKey('r')
        ->and($byId[$medium->id]['k'])->toBe(150000)
        ->and($byId[$medium->id]['t'])->toBe([[20, 120000]])
        ->and($html)->toContain('data-tier-table');
});

it('lets the variant picker recalculate the price from the typed quantity (GP-10)', function (): void {
    $js = file_get_contents(base_path('resources/js/app.js'));

    // The client side of the contract: tiers [min, kopecks], the last tier reached wins, the total is unit x qty.
    expect($js)->toContain('const tierUnit = (base, tiers, qty)')
        ->and($js)->toContain("Alpine.data('tierPrice'")
        ->and($js)->toContain('unitKop()')
        ->and($js)->toContain('totalKop()');
});

it('shows the retail price struck through on the product page when the group asks for it (GP-09)', function (): void {
    $retail = CustomerGroup::factory()->discount(20)->showRetailPrice()->create();
    gpSignInAs($this, $retail);

    $this->get(route('product.show', $this->product->slug))->assertOk()
        ->assertSeeText("800\u{00A0}₽")->assertSee('data-retail-price', false)->assertSeeText("1\u{202F}000\u{00A0}₽");
});

it('shows no group data and no tiers when the group sees no prices (GP-20)', function (): void {
    $hidden = CustomerGroup::factory()->priceDisplay(PriceDisplayMode::Login)->create();
    gpTier($this->product, $hidden, 10, '800.00');
    gpSignInAs($this, $hidden);

    $html = $this->get(route('product.show', $this->product->slug))->assertOk()->getContent();

    expect($html)->toContain('Войдите, чтобы увидеть цену')->not->toContain('data-tier-table')->not->toContain('80000')->not->toContain('"price"');
});
