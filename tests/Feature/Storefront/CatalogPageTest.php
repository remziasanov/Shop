<?php

declare(strict_types=1);

use App\Enums\StockDisplay;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Settings\AppearanceSettings;
use App\Settings\SalesSettings;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

/**
 * Products of one category with explicit names/prices, in a fixed creation order.
 */
function catalogProduct(Category $category, array $attributes = []): Product
{
    return Product::factory()->inCategory($category)->create($attributes + ['brand' => null, 'stock_qty' => 10]);
}

/** @param  array<int, string>  $names */
function listedNames(string $html, array $names): array
{
    $positions = [];
    foreach ($names as $name) {
        $position = strpos($html, '>'.$name.'</a>');
        if ($position !== false) {
            $positions[$position] = $name;
        }
    }
    ksort($positions);

    return array_values($positions);
}

it('shows root categories and products on /catalog (SF-10)', function () {
    $root = Category::factory()->create(['name' => 'Мебельная']);
    $child = Category::factory()->childOf($root)->create(['name' => 'Подкатегория']);
    $product = catalogProduct($root, ['name' => 'Стол обеденный']);

    $this->get(route('catalog.index'))
        ->assertOk()
        ->assertSee('Мебельная')
        ->assertSee('Стол обеденный')
        ->assertSee(route('product.show', $product->slug), false);

    // Only root categories are tiles on /catalog (the child is in the header menu, not in the tiles).
    expect(substr_count($this->get(route('catalog.index'))->getContent(), 'href="'.route('catalog.category', $child->slug).'" class="flex h-full'))->toBe(0);
});

it('shows products of subcategories on a category page (SF-11)', function () {
    $root = Category::factory()->create(['name' => 'Мебель', 'description' => '<p>Описание мебели</p><script>alert(1)</script>']);
    $child = Category::factory()->childOf($root)->create(['name' => 'Кресла']);
    $other = Category::factory()->create(['name' => 'Свет']);

    catalogProduct($root, ['name' => 'Товар корня']);
    catalogProduct($child, ['name' => 'Товар подкатегории']);
    catalogProduct($other, ['name' => 'Чужой товар']);

    $this->get(route('catalog.category', $root->slug))
        ->assertOk()
        ->assertSee('Товар корня')
        ->assertSee('Товар подкатегории')
        ->assertDontSee('Чужой товар')
        ->assertSee('Кресла')
        ->assertSee('Описание мебели')
        ->assertDontSee('alert(1)', false);
});

it('returns 404 for inactive categories and hides their products (SF-16)', function () {
    $inactive = Category::factory()->inactive()->create(['name' => 'Скрытая']);
    $child = Category::factory()->childOf($inactive)->create(['name' => 'Дочерняя скрытой']);
    $visible = Category::factory()->create();
    $hiddenProduct = catalogProduct($child, ['name' => 'Невидимый товар']);
    catalogProduct($visible, ['name' => 'Видимый товар']);

    $this->get(route('catalog.category', $inactive->slug))->assertNotFound();
    $this->get(route('catalog.category', $child->slug))->assertNotFound();
    $this->get(route('product.show', $hiddenProduct->slug))->assertNotFound();
    $this->get(route('catalog.index'))
        ->assertOk()
        ->assertSee('Видимый товар')
        ->assertDontSee('Невидимый товар')
        ->assertDontSee('Скрытая');
});

it('returns 404 for an unknown category', function () {
    $this->get('/catalog/no-such-category')->assertNotFound();
});

it('sorts by price ascending and descending (SF-12)', function () {
    $category = Category::factory()->create();
    catalogProduct($category, ['name' => 'Средний', 'base_price' => '500.00']);
    catalogProduct($category, ['name' => 'Дешёвый', 'base_price' => '100.00']);
    catalogProduct($category, ['name' => 'Дорогой', 'base_price' => '900.00']);
    $names = ['Средний', 'Дешёвый', 'Дорогой'];

    $asc = $this->get(route('catalog.category', [$category->slug, 'sort' => 'price_asc']))->assertOk()->getContent();
    $desc = $this->get(route('catalog.category', [$category->slug, 'sort' => 'price_desc']))->assertOk()->getContent();

    expect(listedNames($asc, $names))->toBe(['Дешёвый', 'Средний', 'Дорогой'])
        ->and(listedNames($desc, $names))->toBe(['Дорогой', 'Средний', 'Дешёвый']);
});

it('sorts by default with manual order first, then newest', function () {
    $category = Category::factory()->create();
    catalogProduct($category, ['name' => 'Первый созданный', 'sort_order' => 5]);
    catalogProduct($category, ['name' => 'Второй созданный', 'sort_order' => 5]);
    catalogProduct($category, ['name' => 'Закреплённый', 'sort_order' => 1]);

    $html = $this->get(route('catalog.category', $category->slug))->getContent();

    expect(listedNames($html, ['Первый созданный', 'Второй созданный', 'Закреплённый']))
        ->toBe(['Закреплённый', 'Второй созданный', 'Первый созданный']);
});

it('ignores invalid sort and filter values instead of failing', function () {
    $category = Category::factory()->create();
    catalogProduct($category, ['name' => 'Обычный товар']);

    $this->get(route('catalog.category', [$category->slug, 'sort' => 'bogus', 'price_from' => 'abc', 'brands' => 'x', 'flags' => ['zzz'], 'in_stock' => ['a']]))
        ->assertOk()
        ->assertSee('Обычный товар');
});

it('filters by price range (SF-13)', function () {
    $category = Category::factory()->create();
    catalogProduct($category, ['name' => 'Дешёвый', 'base_price' => '100.00']);
    catalogProduct($category, ['name' => 'Средний', 'base_price' => '500.00']);
    catalogProduct($category, ['name' => 'Дорогой', 'base_price' => '900.00']);

    $this->get(route('catalog.category', [$category->slug, 'price_from' => '200', 'price_to' => '600']))
        ->assertOk()
        ->assertSee('Средний')
        ->assertDontSee('Дешёвый')
        ->assertDontSee('Дорогой');
});

it('filters by availability and ignores the filter when stock tracking is off (SF-13)', function () {
    $category = Category::factory()->create();
    catalogProduct($category, ['name' => 'Есть', 'stock_qty' => 5]);
    catalogProduct($category, ['name' => 'Нет', 'stock_qty' => 0]);
    catalogProduct($category, ['name' => 'Всё зарезервировано', 'stock_qty' => 2, 'reserved_qty' => 2]);
    catalogProduct($category, ['name' => 'Без учёта', 'stock_qty' => null]);

    $this->get(route('catalog.category', [$category->slug, 'in_stock' => 1]))
        ->assertOk()
        ->assertSee('Есть')
        ->assertSee('Без учёта')
        ->assertDontSee('>Нет<', false)
        ->assertDontSee('Всё зарезервировано');

    $sales = app(SalesSettings::class);
    $sales->stock_tracking = false;
    $sales->save();

    $this->get(route('catalog.category', [$category->slug, 'in_stock' => 1]))
        ->assertOk()
        ->assertSee('Всё зарезервировано');
});

it('filters by brand and lists brands only from the current selection (SF-13)', function () {
    $category = Category::factory()->create();
    $other = Category::factory()->create();
    catalogProduct($category, ['name' => 'Товар Севера', 'brand' => 'Север']);
    catalogProduct($category, ['name' => 'Товар Аврора', 'brand' => 'Аврора']);
    catalogProduct($other, ['name' => 'Чужой бренд', 'brand' => 'Посторонний']);

    $response = $this->get(route('catalog.category', [$category->slug, 'brands' => ['Север']]))->assertOk();

    $response->assertSee('Товар Севера')
        ->assertDontSee('Товар Аврора')
        // The brand list is computed before the brand filter, so other brands stay selectable.
        ->assertSee('value="Аврора"', false)
        ->assertSee('value="Север"', false)
        ->assertDontSee('value="Посторонний"', false);
});

it('filters by labels hit, new and sale (SF-13)', function () {
    $category = Category::factory()->create();
    catalogProduct($category, ['name' => 'Хитовый', 'is_featured' => true]);
    catalogProduct($category, ['name' => 'Новый', 'is_new' => true]);
    $sale = catalogProduct($category, ['name' => 'Со скидкой', 'base_price' => '800.00', 'compare_at_price' => '1000.00']);
    catalogProduct($category, ['name' => 'Обычный']);

    expect($sale->display_old_price)->not->toBeNull();

    $this->get(route('catalog.category', [$category->slug, 'flags' => ['hit']]))
        ->assertSee('Хитовый')->assertDontSee('Новый')->assertDontSee('Со скидкой')->assertDontSee('Обычный');
    $this->get(route('catalog.category', [$category->slug, 'flags' => ['new']]))
        ->assertSee('Новый')->assertDontSee('Хитовый')->assertDontSee('Обычный');
    $this->get(route('catalog.category', [$category->slug, 'flags' => ['sale']]))
        ->assertSee('Со скидкой')->assertDontSee('Хитовый')->assertDontSee('Обычный');
});

it('paginates by the per-page setting and keeps the query string (SF-14)', function () {
    $category = Category::factory()->create();
    foreach (range(1, 5) as $i) {
        catalogProduct($category, ['name' => "Позиция {$i}", 'base_price' => (string) ($i * 100).'.00']);
    }

    $appearance = app(AppearanceSettings::class);
    $appearance->per_page = 2;
    $appearance->save();

    $response = $this->get(route('catalog.category', [$category->slug, 'sort' => 'price_asc']))->assertOk();

    $response->assertSee('Позиция 1')->assertSee('Позиция 2')->assertDontSee('Позиция 3')
        ->assertSee('sort=price_asc&amp;page=2', false);

    $this->get(route('catalog.category', [$category->slug, 'sort' => 'price_asc', 'page' => 3]))
        ->assertOk()->assertSee('Позиция 5')->assertDontSee('Позиция 4');
});

it('renders product card details: price, old price, discount, labels, availability (SF-15)', function () {
    $category = Category::factory()->create();
    $product = catalogProduct($category, [
        'name' => 'Карточка', 'base_price' => '1290.00', 'compare_at_price' => '1720.00', 'is_new' => true, 'stock_qty' => 0,
    ]);
    ProductImage::factory()->create(['product_id' => $product->id, 'width' => 800, 'height' => 600]);

    $this->get(route('catalog.category', $category->slug))
        ->assertOk()
        ->assertSeeText("1\u{202F}290\u{00A0}₽")
        ->assertSeeText("1\u{202F}720\u{00A0}₽")
        ->assertSeeText('−25%')
        ->assertSeeText('Новинка')
        ->assertSeeText('Скидка')
        ->assertSeeText('Нет в наличии')
        ->assertSee('loading="lazy"', false)
        ->assertSee('srcset=', false)
        ->assertSee('width="800"', false)
        ->assertSee('height="600"', false);
});

it('shows action buttons by sales mode (SF-15)', function () {
    $category = Category::factory()->create();
    catalogProduct($category, ['name' => 'Корзинный', 'sales_mode' => 'cart']);
    catalogProduct($category, ['name' => 'Двойной', 'sales_mode' => 'both']);
    catalogProduct($category, ['name' => 'Заявочный', 'sales_mode' => 'inquiry']);
    catalogProduct($category, ['name' => 'Витринный', 'sales_mode' => 'showcase']);

    $html = $this->get(route('catalog.category', $category->slug))->assertOk()->getContent();

    expect(substr_count($html, 'В корзину'))->toBe(2)
        ->and(substr_count($html, 'Зарезервировать'))->toBe(1)
        ->and(substr_count($html, 'Оставить заявку'))->toBe(1);
});

it('shows availability by the stock display mode and hides it without tracking (SF-32)', function () {
    $category = Category::factory()->create();
    catalogProduct($category, ['name' => 'Мало осталось', 'stock_qty' => 2]);
    catalogProduct($category, ['name' => 'Много', 'stock_qty' => 50]);

    $sales = app(SalesSettings::class);
    $sales->stock_display = StockDisplay::Low;
    $sales->low_stock_threshold = 3;
    $sales->save();

    $this->get(route('catalog.category', $category->slug))->assertSeeText('Мало')->assertSeeText('В наличии');

    $sales->stock_display = StockDisplay::Exact;
    $sales->save();
    $this->get(route('catalog.category', $category->slug))->assertSeeText('В наличии: 50 шт');

    $sales->stock_tracking = false;
    $sales->save();
    $this->get(route('catalog.category', $category->slug))->assertDontSeeText('В наличии')->assertDontSeeText('Нет в наличии');
});

it('adds a canonical link without the sort parameter, and breadcrumbs (SF-14, SF-06)', function () {
    $root = Category::factory()->create(['name' => 'Корень']);
    $child = Category::factory()->childOf($root)->create(['name' => 'Лист']);
    catalogProduct($child);

    $canonical = route('catalog.category', $child->slug);

    $this->get(route('catalog.category', [$child->slug, 'sort' => 'price_asc']))
        ->assertOk()
        ->assertSee('<link rel="canonical" href="'.$canonical.'">', false)
        ->assertSee(route('catalog.category', $root->slug), false)
        ->assertSee('BreadcrumbList', false);

    $this->get(route('catalog.index'))
        ->assertSee('<link rel="canonical" href="'.route('catalog.index').'">', false);
});

it('builds the header category menu from visible categories only (SF-01)', function () {
    $root = Category::factory()->create(['name' => 'Видимая корневая']);
    Category::factory()->childOf($root)->create(['name' => 'Видимая дочерняя']);
    Category::factory()->childOf($root)->inactive()->create(['name' => 'Скрытая дочерняя']);
    Category::factory()->inactive()->create(['name' => 'Скрытая корневая']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Видимая корневая')
        ->assertSee('Видимая дочерняя')
        ->assertDontSee('Скрытая дочерняя')
        ->assertDontSee('Скрытая корневая');
});

it('stays within 30 queries on a category page with 24 products (NF-02)', function () {
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();

    foreach (range(1, 24) as $i) {
        $product = catalogProduct($i % 2 === 0 ? $child : $root, ['brand' => 'Бренд '.($i % 3)]);
        ProductImage::factory()->create(['product_id' => $product->id]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->get(route('catalog.category', $root->slug))->assertOk();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(30);
});
