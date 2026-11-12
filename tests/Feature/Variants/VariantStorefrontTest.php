<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Livewire\Storefront\InquiryForm;
use App\Livewire\Storefront\ReservationForm;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductImage;
use App\Services\Seo\SitemapGenerator;
use App\Services\Variants\VariantAggregator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportTesting\Testable;

require_once __DIR__.'/../../Support/variant_storefront.php';

beforeEach(function (): void {
    $this->withoutVite();
    resetFormGuard();
    sales(['stock_tracking' => true, 'allow_backorder' => false, 'reservation_hours' => 48]);
    $this->shirt = vsShirt();
    $this->card = $this->shirt['card'];
    $this->url = route('product.show', $this->card->slug);
});

/**
 * The embedded picker data of a product page.
 *
 * @return array<string, mixed>
 */
function pickerData(string $html): array
{
    expect(preg_match('#<script type="application/json" id="variant-data">(.*?)</script>#s', $html, $m))->toBe(1);

    return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The schema.org Product block of a page.
 *
 * @return array<string, mixed>
 */
function productJsonLd(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    foreach ($matches[1] as $json) {
        $data = json_decode($json, true);

        if (($data['@type'] ?? null) === 'Product') {
            return $data;
        }
    }

    return [];
}

// ---------------------------------------------------------------- listing (VR-08)

it('shows «от X ₽» and «Выбрать» for a card whose variants differ in price, with no cart form (VR-08)', function (): void {
    $response = $this->get(route('catalog.category', $this->card->category->slug))->assertOk();
    $html = $response->getContent();

    $response->assertSeeText("от 1\u{202F}000\u{00A0}₽");
    expect($html)->toContain('data-action="select"')
        ->and($html)->toContain('>Выбрать<')
        ->and($html)->not->toContain('name="product_id"')
        ->and($html)->not->toContain('data-action="cart"');
});

it('shows a plain price when all variants cost the same and adds nothing to the cart from the list', function (): void {
    $this->shirt['redM']->update(['base_price' => '1000.00']);

    $response = $this->get(route('catalog.category', $this->card->category->slug))->assertOk();

    $response->assertSeeText("1\u{202F}000\u{00A0}₽")->assertDontSeeText('от 1');
    expect($response->getContent())->toContain('data-action="select"');
});

it('treats a card as available when at least one variant is (VR-08)', function (): void {
    $this->shirt['redS']->forceFill(['stock_qty' => 0])->saveQuietly();
    $this->shirt['blueS']->forceFill(['stock_qty' => 0])->saveQuietly();
    app(VariantAggregator::class)->rollupStock($this->card->id);

    $this->get(route('catalog.category', $this->card->category->slug))->assertOk()
        ->assertSeeText('Нет в наличии')->assertDontSee('data-action="select"', false);

    $this->shirt['blueS']->forceFill(['stock_qty' => 4])->saveQuietly();
    app(VariantAggregator::class)->rollupStock($this->card->id);

    $this->get(route('catalog.category', $this->card->category->slug))->assertOk()
        ->assertSee('data-action="select"', false);
});

it('sorts by the lowest price and filters by the price span of the card (VR-07)', function (): void {
    $category = $this->card->category;
    $cheap = variantCard(['name' => 'Дешёвая', 'sku' => 'CHEAP', 'category_id' => $category->id], $this->shirt['size']);
    variantOf($cheap, [$this->shirt['size']->values[0]], ['sku' => 'CH-S', 'base_price' => '500.00']);
    $dear = variantCard(['name' => 'Дорогая', 'sku' => 'DEAR', 'category_id' => $category->id], $this->shirt['size']);
    variantOf($dear, [$this->shirt['size']->values[0]], ['sku' => 'DR-S', 'base_price' => '3000.00']);

    $url = route('catalog.category', $category->slug);
    $order = fn (string $query): array => array_map(fn ($view) => $view->product->name, $this->get($url.$query)->assertOk()->viewData('cards'));

    expect($order('?sort=price_asc'))->toBe(['Дешёвая', 'Футболка', 'Дорогая'])
        ->and($order('?sort=price_desc'))->toBe(['Дорогая', 'Футболка', 'Дешёвая'])
        // 1100 ₽ is inside the span 1000…1200 of the T-shirt although its lowest price is below.
        ->and($order('?price_from=1100&price_to=1500&sort=price_asc'))->toBe(['Футболка'])
        ->and($order('?price_to=900'))->toBe(['Дешёвая'])
        ->and($order('?price_from=1300&price_to=2000'))->toBe([])
        ->and($order('?price_from=2000&sort=price_asc'))->toBe(['Дорогая']);
});

// ---------------------------------------------------------------- product page (VR-09, VR-10, VR-28)

it('embeds the variants as JSON and opens on the first available one (VR-09, VR-10)', function (): void {
    $html = $this->get($this->url)->assertOk()->getContent();
    $data = pickerData($html);

    expect($data['selected'])->toBe($this->shirt['redS']->id)
        ->and($data['variants'])->toHaveCount(3)
        ->and(array_column($data['axes'], 'name'))->toBe(['Цвет', 'Размер'])
        ->and(array_column($data['axes'], 'display'))->toBe(['color', 'button'])
        ->and(array_column($data['axes'][0]['values'], 'hex'))->toBe(['#ff0000', '#0000ff'])
        ->and(array_column($data['axes'][1]['values'], 'text'))->toBe(['S', 'M']);

    $red = collect($data['variants'])->firstWhere('id', $this->shirt['redS']->id);
    $redM = collect($data['variants'])->firstWhere('id', $this->shirt['redM']->id);

    expect($red['sku'])->toBe('TS-RED-S')
        ->and($red['bc'])->toBe('4600000000011')
        ->and($red['v'])->toBe([$this->shirt['color']->values[0]->id, $this->shirt['size']->values[0]->id])
        ->and($red['p'])->toBe("1\u{202F}000\u{00A0}₽")
        ->and($red['c'] & 1)->toBe(1)
        ->and($redM['p'])->toBe("1\u{202F}200\u{00A0}₽")
        // «нет в наличии»: shaded but selectable, no cart.
        ->and($redM['c'] & 8)->toBe(8)
        ->and($redM['c'] & 1)->toBe(0);

    // Server-side first paint of the selected variant and the picker markup.
    expect($html)->toContain('x-data="variantPicker(')
        ->and($html)->toContain('data-variant-sku')
        ->and($html)->toContain('TS-RED-S')
        ->and($html)->toContain('data-variant-picker');
});

it('preselects the variant of ?variant= with the canonical link to the card (VR-10)', function (): void {
    $html = $this->get($this->url.'?variant='.$this->shirt['blueS']->id)->assertOk()->getContent();

    expect(pickerData($html)['selected'])->toBe($this->shirt['blueS']->id)
        ->and($html)->toContain('<link rel="canonical" href="'.$this->url.'">')
        ->and($html)->toContain('TS-BLUE-S');

    // An unknown, inactive or foreign id falls back to the first available variant.
    $other = vsShirt(['name' => 'Другая'], 'OT');
    expect(pickerData($this->get($this->url.'?variant=999999')->getContent())['selected'])->toBe($this->shirt['redS']->id)
        ->and(pickerData($this->get($this->url.'?variant='.$other['redS']->id)->getContent())['selected'])->toBe($this->shirt['redS']->id)
        ->and(pickerData($this->get($this->url.'?variant=abc')->getContent())['selected'])->toBe($this->shirt['redS']->id);

    $this->shirt['blueS']->forceFill(['is_active' => false])->saveQuietly();
    expect(pickerData($this->get($this->url.'?variant='.$this->shirt['blueS']->id)->getContent())['variants'])->toHaveCount(2);
});

it('keeps ?variant= URLs out of the sitemap (VR-10)', function (): void {
    $dir = storage_path('framework/testing/sitemap-'.uniqid());
    File::ensureDirectoryExists($dir);
    config(['shop.sitemap_dir' => $dir, 'app.url' => 'https://shop.example']);

    app(SitemapGenerator::class)->generate();
    $xml = (string) file_get_contents($dir.'/sitemap.xml');
    File::deleteDirectory($dir);

    expect($xml)->toContain('/product/'.$this->card->slug.'</loc>')->and($xml)->not->toContain('variant=');
});

it('describes the card with an AggregateOffer and the article of the first available variant (VR-28)', function (): void {
    $product = productJsonLd($this->get($this->url)->getContent());

    expect($product['offers']['@type'])->toBe('AggregateOffer')
        ->and($product['offers']['lowPrice'])->toBe('1000.00')
        ->and($product['offers']['highPrice'])->toBe('1200.00')
        ->and($product['offers']['offerCount'])->toBe(3)
        ->and($product['offers']['priceCurrency'])->toBe('RUB')
        ->and($product['offers']['availability'])->toBe('https://schema.org/InStock')
        ->and($product['sku'])->toBe('TS-RED-S');

    foreach (['redS', 'blueS'] as $key) {
        $this->shirt[$key]->forceFill(['stock_qty' => 0])->saveQuietly();
    }

    expect(productJsonLd($this->get($this->url)->getContent())['offers']['availability'])->toBe('https://schema.org/OutOfStock');
});

it('describes the selected variant with an Offer when opened with ?variant= (VR-28)', function (): void {
    $product = productJsonLd($this->get($this->url.'?variant='.$this->shirt['redM']->id)->getContent());

    expect($product['offers']['@type'])->toBe('Offer')
        ->and($product['offers']['price'])->toBe('1200.00')
        ->and($product['offers']['sku'])->toBe('TS-RED-M')
        ->and($product['offers']['availability'])->toBe('https://schema.org/OutOfStock')
        ->and($product['sku'])->toBe('TS-RED-M')
        ->and($product['offers'])->not->toHaveKey('lowPrice');
});

it('gives the gallery the photos of a colour next to the common ones (VR-11)', function (): void {
    $red = $this->shirt['color']->values[0];
    ProductImage::factory()->create(['product_id' => $this->card->id, 'sort_order' => 1]);
    ProductImage::factory()->create(['product_id' => $this->card->id, 'sort_order' => 2, 'option_value_id' => $red->id]);
    ProductImage::factory()->create(['product_id' => $this->card->id, 'sort_order' => 3, 'option_value_id' => $this->shirt['color']->values[1]->id]);

    $this->get($this->url)->assertOk()
        ->assertSee('productGallery(3, ', false)
        ->assertSee('[null,'.$red->id.','.$this->shirt['color']->values[1]->id.']', false);
});

it('stays within the query budget of the product page with 100 variants and ~15 KB of JSON (NF-02, VR-19.7)', function (): void {
    $size = optionWith('Рост', array_map(fn (int $i): string => 'R'.$i, range(1, 10)));
    $shade = optionWith('Оттенок', array_map(fn (int $i): string => 'T'.$i, range(1, 10)));
    $card = variantCard(['name' => 'Большая', 'sku' => 'BIG'], $size, $shade);

    foreach ($size->values as $i => $a) {
        foreach ($shade->values as $j => $b) {
            variantOf($card, [$a, $b], ['sku' => "BIG-$i-$j", 'barcode' => sprintf('46000000%05d', $i * 10 + $j), 'base_price' => (string) (1000 + $i + $j).'.00']);
        }
    }

    Cache::flush();
    DB::enableQueryLog();
    $html = $this->get(route('product.show', $card->slug))->assertOk()->getContent();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    $json = (string) preg_replace('#^.*<script type="application/json" id="variant-data">(.*?)</script>.*$#s', '$1', $html);

    expect(pickerData($html)['variants'])->toHaveCount(100)
        ->and(strlen($json))->toBeLessThan(20_000)
        ->and($queries)->toBeLessThanOrEqual(30);

    // The plain page costs the same minus the two variant queries: nothing else grew with the number of variants.
    $plain = sellable();
    Cache::flush();
    DB::enableQueryLog();
    $this->get(route('product.show', $plain->slug))->assertOk();
    $plainQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries - $plainQueries)->toBeLessThanOrEqual(3);
});

it('leaves a card without variants exactly as before', function (): void {
    $plain = sellable(['name' => 'Обычный', 'sku' => 'PLAIN-1', 'base_price' => '700.00', 'stock_qty' => 3]);
    $html = $this->get(route('product.show', $plain->slug))->assertOk()->getContent();

    expect($html)->not->toContain('variant-data')
        ->and($html)->not->toContain('variantPicker')
        ->and($html)->toContain('productGallery(0)')
        ->and(productJsonLd($html)['offers']['@type'])->toBe('Offer');
});

// ---------------------------------------------------------------- reservation and inquiry forms (VR-14)

/**
 * @param  Testable  $component
 */
function vsContacts($component): mixed
{
    foreach (['name' => 'Иван Тестов', 'phone' => '8 900 123-45-67', 'email' => '', 'comment' => '', 'consent' => true] as $key => $value) {
        $component->set($key, $value);
    }

    return $component;
}

it('reserves the variant chosen in the form of the product page (VR-14)', function (): void {
    $component = livewireWithCart(ReservationForm::class, null, ['productId' => $this->card->id, 'variantId' => $this->shirt['blueS']->id]);
    $this->travel(5)->seconds();

    vsContacts($component)->set('qty', 2)->call('submit')->assertHasNoErrors();

    $order = Order::query()->latest('id')->first();

    expect($order->type)->toBe(OrderType::Reservation)
        ->and($order->items->first()->variant_id)->toBe($this->shirt['blueS']->id)
        ->and($order->items->first()->variant_label)->toBe('Цвет: Синий, Размер: S')
        ->and($this->shirt['blueS']->fresh()->reserved_qty)->toBe(2)
        ->and($this->card->fresh()->reserved_qty)->toBe(2);
});

it('refuses the form without a variant and over the stock of the chosen one (VR-14)', function (): void {
    $component = livewireWithCart(ReservationForm::class, null, ['productId' => $this->card->id]);
    $this->travel(5)->seconds();

    vsContacts($component)->set('qty', 1)->call('submit')->assertHasErrors('form');

    $component->set('variantId', $this->shirt['blueS']->id)->set('qty', 3)->call('submit')->assertHasErrors('qty');

    expect(Order::count())->toBe(0);
});

it('sends an inquiry for the chosen variant with the same snapshot (VR-14)', function (): void {
    $component = livewireWithCart(InquiryForm::class, null, ['productId' => $this->card->id, 'variantId' => $this->shirt['redM']->id]);
    $this->travel(5)->seconds();

    vsContacts($component)->set('qty', 1)->call('submit')->assertHasNoErrors();

    $item = Order::query()->latest('id')->first()->items->first();

    expect($item->variant_id)->toBe($this->shirt['redM']->id)->and($item->sku)->toBe('TS-RED-M')->and($item->price)->toBe('1200.00');
});

it('renders the account order page with the variant label', function (): void {
    $customer = signInCustomer($this, makeCustomer());
    $order = Order::factory()->order()->create(['customer_id' => $customer->id]);
    OrderItem::factory()->forVariant($this->shirt['redS'], 1)->create(['order_id' => $order->id, 'variant_label' => 'Цвет: Красный, Размер: S']);

    $this->get(route('customer.orders.show', $order->uuid))->assertOk()->assertSee('Цвет: Красный, Размер: S');
});
