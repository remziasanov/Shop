<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;

beforeEach(function () {
    $this->withoutVite();
});

it('finds products where every word matches (SF-20)', function () {
    Product::factory()->create(['name' => 'Кресло офисное', 'brand' => 'Север']);
    Product::factory()->create(['name' => 'Кресло садовое', 'brand' => 'Уют']);
    Product::factory()->create(['name' => 'Стол офисный', 'brand' => 'Север']);

    $this->get(route('search', ['q' => 'кресло офисное']))
        ->assertOk()
        ->assertSee('Кресло офисное')
        ->assertDontSee('Кресло садовое')
        ->assertDontSee('Стол офисный');

    $this->get(route('search', ['q' => 'КРЕСЛО  север']))
        ->assertSee('Кресло офисное')
        ->assertDontSee('Кресло садовое');
});

it('puts an exact SKU match first (SF-20)', function () {
    Product::factory()->create(['name' => 'Аксессуар к ab-100 крепёж', 'sku' => 'ZZ-1']);
    Product::factory()->create(['name' => 'Лампа настольная', 'sku' => 'AB-100']);

    $html = $this->get(route('search', ['q' => 'ab-100']))->assertOk()->getContent();

    expect(strpos($html, '>Лампа настольная</a>'))->toBeLessThan(strpos($html, '>Аксессуар к ab-100 крепёж</a>'));
});

it('treats ё and е the same (SF-20)', function () {
    Product::factory()->create(['name' => 'Ёлка искусственная']);
    Product::factory()->create(['name' => 'Подсвечник железный']);

    $this->get(route('search', ['q' => 'елка']))->assertSee('Ёлка искусственная')->assertDontSee('Подсвечник');
    $this->get(route('search', ['q' => 'Ёлка']))->assertSee('Ёлка искусственная');
});

it('does not find inactive products or products of hidden categories (SF-16)', function () {
    Product::factory()->inactive()->create(['name' => 'Тайный неактивный']);
    $hidden = Category::factory()->inactive()->create();
    Product::factory()->inCategory($hidden)->create(['name' => 'Тайный в скрытой']);
    Product::factory()->create(['name' => 'Тайный открытый']);

    $this->get(route('search', ['q' => 'тайный']))
        ->assertSee('Тайный открытый')
        ->assertDontSee('Тайный неактивный')
        ->assertDontSee('Тайный в скрытой');
});

it('escapes LIKE wildcards in the query', function () {
    Product::factory()->create(['name' => 'Обычный товар']);

    $this->get(route('search', ['q' => '%']))->assertOk()->assertDontSee('Обычный товар');
});

it('is noindex, has no canonical, and asks for a query when empty (SF-22)', function () {
    $this->get(route('search', ['q' => 'что-то']))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex,follow">', false)
        ->assertDontSee('rel="canonical"', false);

    $this->get(route('search'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex,follow">', false)
        ->assertSeeText('Введите запрос');
});

it('shows a message when nothing is found', function () {
    $this->get(route('search', ['q' => 'несуществующее']))->assertOk()->assertSeeText('ничего не найдено');
});

it('supports the same sort and filters as the catalog (SF-22)', function () {
    Product::factory()->create(['name' => 'Лампа дешёвая', 'base_price' => '100.00']);
    Product::factory()->create(['name' => 'Лампа дорогая', 'base_price' => '900.00']);

    $html = $this->get(route('search', ['q' => 'лампа', 'sort' => 'price_desc']))->getContent();
    expect(strpos($html, '>Лампа дорогая</a>'))->toBeLessThan(strpos($html, '>Лампа дешёвая</a>'));

    $this->get(route('search', ['q' => 'лампа', 'price_from' => '500']))
        ->assertSee('Лампа дорогая')->assertDontSee('Лампа дешёвая')
        ->assertSee('name="q" value="лампа"', false);
});

it('returns up to 8 suggestions as JSON (SF-21)', function () {
    $products = Product::factory()->count(12)->sequence(fn ($s) => ['name' => 'Тумба номер '.($s->index + 1)])->create();
    ProductImage::factory()->create(['product_id' => $products->first()->id]);

    $response = $this->getJson(route('search.suggest', ['q' => 'тумба']))->assertOk();

    expect($response->json('items'))->toHaveCount(8);
    $response->assertJsonStructure(['items' => [['name', 'sku', 'url', 'price', 'image']]]);
});

it('returns an empty list for queries shorter than 2 characters (SF-21)', function () {
    Product::factory()->create(['name' => 'Яблоко']);

    $this->getJson(route('search.suggest', ['q' => 'я']))->assertOk()->assertExactJson(['items' => []]);
    $this->getJson(route('search.suggest'))->assertOk()->assertExactJson(['items' => []]);
});

it('formats suggestion prices and hides them for price-on-request products', function () {
    Product::factory()->create(['name' => 'Полка ценная', 'base_price' => '1290.00']);
    Product::factory()->priceOnRequest()->create(['name' => 'Полка секретная', 'base_price' => '5555.00']);

    $items = collect($this->getJson(route('search.suggest', ['q' => 'полка']))->json('items'))->keyBy('name');

    expect($items['Полка ценная']['price'])->toBe("1\u{202F}290\u{00A0}₽")
        ->and($items['Полка секретная']['price'])->toBe('Цена по запросу');
});

it('throttles suggestions (60 per minute)', function () {
    foreach (range(1, 60) as $i) {
        $this->getJson(route('search.suggest', ['q' => 'ab']))->assertOk();
    }

    $this->getJson(route('search.suggest', ['q' => 'ab']))->assertStatus(429);
});
