<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Settings\GeneralSettings;
use App\Settings\SalesSettings;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

it('shows name, sku, brand, price, old price and discount (SF-30, SF-31)', function () {
    $category = Category::factory()->create(['name' => 'Кресла']);
    $product = Product::factory()->inCategory($category)->create([
        'name' => 'Кресло Бриз', 'sku' => 'KR-100', 'brand' => 'Аврора', 'unit' => 'шт',
        'base_price' => '1290.00', 'compare_at_price' => '1720.00', 'is_featured' => true,
    ]);

    $this->get(route('product.show', $product->slug))
        ->assertOk()
        ->assertSee('<title>Кресло Бриз — ', false)
        ->assertSeeText('Кресло Бриз')
        ->assertSeeText('KR-100')
        ->assertSeeText('Аврора')
        ->assertSeeText("1\u{202F}290\u{00A0}₽")
        ->assertSeeText("1\u{202F}720\u{00A0}₽")
        ->assertSeeText('−25%')
        ->assertSeeText('за шт')
        ->assertSeeText('Хит')
        ->assertSee('<link rel="canonical" href="'.route('product.show', $product->slug).'">', false)
        ->assertSee(route('catalog.category', $category->slug), false);
});

it('shows specs and sanitized description in tabs (SF-34)', function () {
    $product = Product::factory()->create([
        'description' => '<p>Удобное кресло</p><script>alert("xss")</script><img src="x" onerror="alert(2)">',
        'short_description' => 'Короткое описание рядом с ценой',
        'attributes' => [['name' => 'Материал', 'value' => 'Дерево'], ['name' => 'Вес', 'value' => '5 кг']],
    ]);

    $this->get(route('product.show', $product->slug))
        ->assertOk()
        ->assertSeeText('Описание')
        ->assertSeeText('Характеристики')
        ->assertSeeText('Короткое описание рядом с ценой')
        ->assertSee('Удобное кресло')
        ->assertSeeText('Материал')
        ->assertSeeText('Дерево')
        ->assertSeeText('5 кг')
        ->assertDontSee('alert("xss")', false)
        ->assertDontSee('onerror', false);
});

it('renders the gallery with thumbnails, or a placeholder without photos (SF-30)', function () {
    $with = Product::factory()->create();
    ProductImage::factory()->count(3)->sequence(['sort_order' => 0], ['sort_order' => 1], ['sort_order' => 2])->create(['product_id' => $with->id]);
    $without = Product::factory()->create(['name' => 'Без фото']);

    $this->get(route('product.show', $with->slug))
        ->assertOk()
        ->assertSee('productGallery(3)', false)
        ->assertSee('srcset=', false)
        ->assertSee('role="dialog"', false);

    $this->get(route('product.show', $without->slug))
        ->assertOk()
        ->assertSee('<svg', false)
        ->assertDontSee('data-gallery-open', false);
});

it('hides the price for price-on-request products (SF-36)', function () {
    $product = Product::factory()->priceOnRequest()->create(['name' => 'Эксклюзив', 'base_price' => '77777.00']);

    $this->get(route('product.show', $product->slug))
        ->assertOk()
        ->assertSeeText('Цена по запросу')
        ->assertSeeText('Оставить заявку')
        ->assertDontSee(Money::format(7777700))
        ->assertDontSeeText('77777')
        ->assertDontSeeText('В корзину');

    $category = Category::factory()->create();
    Product::factory()->inCategory($category)->priceOnRequest()->create(['base_price' => '88888.00']);
    $this->get(route('catalog.category', $category->slug))
        ->assertSeeText('Цена по запросу')
        ->assertDontSee(Money::format(8888800))
        ->assertDontSeeText('88888');
});

it('shows availability by stock settings (SF-32)', function () {
    $product = Product::factory()->create(['stock_qty' => 7, 'unit' => 'шт']);

    $this->get(route('product.show', $product->slug))->assertSeeText('В наличии');

    $sales = app(SalesSettings::class);
    $sales->stock_tracking = false;
    $sales->save();

    $this->get(route('product.show', $product->slug))->assertDontSeeText('В наличии');
});

it('shows up to 8 similar active products of the same category (SF-35)', function () {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create(['name' => 'Главный товар']);
    Product::factory()->inCategory($category)->count(10)->sequence(fn ($s) => ['name' => 'Похожий '.($s->index + 1)])->create();
    Product::factory()->inCategory($category)->inactive()->create(['name' => 'Похожий неактивный']);
    Product::factory()->inCategory($other)->create(['name' => 'Из другой категории']);

    $html = $this->get(route('product.show', $product->slug))->assertOk()->getContent();

    expect(substr_count($html, '<article'))->toBe(8)
        ->and($html)->not->toContain('Похожий неактивный')
        ->and($html)->not->toContain('Из другой категории');
});

it('returns 404 for inactive, soft-deleted and unknown products (SF-16)', function () {
    $inactive = Product::factory()->inactive()->create();
    $deleted = Product::factory()->create();
    $deleted->delete();

    $this->get(route('product.show', $inactive->slug))->assertNotFound();
    $this->get(route('product.show', $deleted->slug))->assertNotFound();
    $this->get('/product/does-not-exist')->assertNotFound();
});

it('shows buttons per sales mode and none for showcase (SF-33 placeholder)', function () {
    $both = Product::factory()->salesMode(SalesMode::Both)->create();
    $showcase = Product::factory()->salesMode(SalesMode::Showcase)->create();

    $this->get(route('product.show', $both->slug))->assertSeeText('В корзину')->assertSeeText('Зарезервировать');
    $this->get(route('product.show', $showcase->slug))->assertDontSeeText('В корзину')->assertDontSeeText('Зарезервировать')->assertDontSeeText('Оставить заявку');
});

it('stays within 30 queries on a product page (NF-02)', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->create();
    ProductImage::factory()->count(3)->create(['product_id' => $product->id]);
    Product::factory()->inCategory($category)->count(8)->create()->each(fn ($p) => ProductImage::factory()->create(['product_id' => $p->id]));

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->get(route('product.show', $product->slug))->assertOk();
    $count = count(DB::getQueryLog());

    expect($count)->toBeLessThanOrEqual(30);
});

it('requests the 1600 px gallery variants only inside the open fullscreen view (NF-03)', function () {
    $product = Product::factory()->create();
    ProductImage::factory()->count(3)->sequence(['sort_order' => 0], ['sort_order' => 1], ['sort_order' => 2])->create(['product_id' => $product->id]);

    $html = $this->get(route('product.show', $product->slug))->assertOk()->getContent();

    // No plain src attribute (the browser would download it) points to a 1600 px variant ...
    expect(preg_match('/\ssrc="[^"]*-1600\./', $html))->toBe(0)
        // ... they are bound by Alpine, and only while the fullscreen view is open.
        ->and(substr_count($html, ':src="fullscreen && index =='))->toBe(3)
        ->and($html)->toContain('fetchpriority="high"');
});

it('shows the shop phone instead of buttons in showcase mode', function () {
    $settings = app(GeneralSettings::class);
    $settings->phones = ['+7 (900) 123-45-67'];
    $settings->save();
    $showcase = Product::factory()->salesMode(SalesMode::Showcase)->create();
    $cart = Product::factory()->salesMode(SalesMode::Cart)->create();

    $this->get(route('product.show', $showcase->slug))
        ->assertOk()
        ->assertSee('href="tel:+79001234567"', false)
        ->assertSee('data-showcase-phone', false)
        ->assertDontSeeText('В корзину');
    $this->get(route('product.show', $cart->slug))->assertDontSee('data-showcase-phone', false);
});

it('renders the first four listing cards eagerly and the rest lazily', function () {
    $category = Category::factory()->create();
    $products = Product::factory()->count(6)->inCategory($category)->create();
    foreach ($products as $product) {
        ProductImage::factory()->create(['product_id' => $product->id]);
    }

    $html = $this->get(route('catalog.category', $category->slug))->assertOk()->getContent();
    preg_match_all('/<article.*?<\/article>/s', $html, $cards);

    expect($cards[0])->toHaveCount(6);
    foreach ($cards[0] as $i => $card) {
        expect(str_contains($card, 'loading="lazy"'))->toBe($i >= 4);
    }
});

it('survives an array search query in the header', function () {
    $this->get('/?q[]=x')->assertOk()->assertSee('searchSuggest(', false);
});
