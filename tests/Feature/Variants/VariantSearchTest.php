<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/variant_storefront.php';

beforeEach(function (): void {
    $this->withoutVite();
    $this->shirt = vsShirt();
});

it('finds a card by the exact article of an active variant and links to that variant (VR-21)', function (): void {
    $html = $this->get(route('search', ['q' => 'TS-BLUE-S']))->assertOk()->getContent();

    expect($html)->toContain('Футболка')
        ->and($html)->toContain('/product/'.$this->shirt['card']->slug.'?variant='.$this->shirt['blueS']->id);
});

it('finds a card by the exact barcode of a variant (VR-21)', function (): void {
    $html = $this->get(route('search', ['q' => '4600000000011']))->assertOk()->getContent();

    expect($html)->toContain('?variant='.$this->shirt['redS']->id);
});

it('matches the article exactly, not as a part, and ignores inactive and deleted variants (VR-21)', function (): void {
    $this->get(route('search', ['q' => 'TS-BLUE']))->assertOk()->assertDontSee('?variant=', false)->assertSeeText('ничего не найдено');
    $this->get(route('search', ['q' => '46000000000']))->assertOk()->assertDontSee('?variant=', false);

    $this->shirt['blueS']->forceFill(['is_active' => false])->saveQuietly();
    $this->get(route('search', ['q' => 'TS-BLUE-S']))->assertOk()->assertDontSee('?variant=', false);

    $this->shirt['redM']->delete();
    $this->get(route('search', ['q' => 'TS-RED-M']))->assertOk()->assertDontSee('?variant=', false);
});

it('ranks the variant match first when the same phrase also matches other cards by words (VR-21)', function (): void {
    Product::factory()->create(['name' => 'Чехол для TS-RED-S', 'sku' => 'ZZ-1']);

    $html = $this->get(route('search', ['q' => 'TS-RED-S']))->assertOk()->getContent();

    expect(strpos($html, '>Футболка</a>'))->toBeLessThan(strpos($html, '>Чехол для TS-RED-S</a>'));
});

it('uses an indexed exact comparison for variants and no LIKE on them (VR-21)', function (): void {
    DB::enableQueryLog();
    $this->get(route('search', ['q' => 'TS-RED-S']))->assertOk();
    $sql = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $q): bool => str_contains($q, 'product_variants'))->implode(' ');
    DB::disableQueryLog();

    expect($sql)->toMatch('/sku[`"] = \?/')->and($sql)->toMatch('/barcode[`"] = \?/')->and($sql)->not->toMatch('/pv.{0,3}(sku|barcode)[^=]{0,5}like/i');
});

it('suggests the variant article match with a link to the variant (SF-21, VR-21)', function (): void {
    $this->getJson(route('search.suggest', ['q' => 'TS-BLUE-S']))->assertOk()
        ->assertJsonPath('items.0.name', 'Футболка')
        ->assertJsonPath('items.0.url', route('product.show', ['slug' => $this->shirt['card']->slug, 'variant' => $this->shirt['blueS']->id]));
});
