<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Product;
use App\Services\Home\HomePageRenderer;
use App\Settings\AppearanceSettings;
use App\Settings\HomePageSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/home.php';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
});

it('renders the visible blocks in the saved order and skips hidden ones', function () {
    storeHomeBlocks([
        homeBlock('text', ['title' => 'Второй по списку', 'content' => '<p>b</p>']),
        homeBlock('text', ['title' => 'Скрытый блок', 'content' => '<p>hidden</p>', 'visible' => false]),
        homeBlock('text', ['title' => 'Третий по списку', 'content' => '<p>c</p>']),
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSeeInOrder(['Второй по списку', 'Третий по списку'])
        ->assertDontSee('Скрытый блок');
});

it('treats a block without a visibility flag as visible', function () {
    storeHomeBlocks([['type' => 'text', 'data' => ['title' => 'Без флага', 'content' => '<p>x</p>']]]);

    $this->get(route('home'))->assertSee('Без флага');
});

it('falls back to root categories and the 12 latest products when there are no blocks', function () {
    $root = Category::factory()->create(['name' => 'Корневая']);
    Category::factory()->childOf($root)->create(['name' => 'Дочерняя']);
    Category::factory()->inactive()->create(['name' => 'Выключенная']);

    foreach (range(1, 14) as $i) {
        Product::factory()->inCategory($root)->create([
            'name' => sprintf('Товар №%02d', $i),
            'created_at' => Carbon::parse('2026-01-01')->addDays($i),
        ]);
    }

    $response = $this->get(route('home'))->assertOk();

    $main = homeMain($response);
    expect($main)->toContain('Корневая')->not->toContain('Дочерняя')->not->toContain('Выключенная');
    $response->assertSee('Товар №14')->assertSee('Товар №03')->assertDontSee('Товар №02')->assertDontSee('Товар №01');
    expect(substr_count($response->getContent(), '<article'))->toBe(12);
});

it('falls back when every block is hidden', function () {
    $category = Category::factory()->create(['name' => 'Запасная']);
    Product::factory()->inCategory($category)->create(['name' => 'Запасной товар']);
    storeHomeBlocks([homeBlock('text', ['title' => 'Не виден', 'content' => '<p>x</p>', 'visible' => false])]);

    $this->get(route('home'))->assertSee('Запасная')->assertSee('Запасной товар')->assertDontSee('Не виден');
});

it('outputs the html block raw and sanitizes the text block', function () {
    storeHomeBlocks([
        homeBlock('html', ['html' => '<div id="raw-html" onclick="x()"><script>window.__raw = 1</script></div>']),
        homeBlock('text', ['title' => 'Текст', 'content' => '<p>safe</p><script>window.__evil = 1</script><img src="x" onerror="evil()">']),
    ]);

    $response = $this->get(route('home'))->assertOk();

    $response->assertSee('<div id="raw-html" onclick="x()"><script>window.__raw = 1</script></div>', false)
        ->assertSee('<p>safe</p>', false)
        ->assertDontSee('window.__evil', false)
        ->assertDontSee('onerror', false);
});

it('renders banners with a picture, a lazy tail and a high-priority first image', function () {
    storeHomeBlocks([
        homeBlock('banner', ['title' => 'Первый', 'image' => bannerImage('one'), 'image_mobile' => bannerImage('mob'), 'button_text' => 'Купить', 'button_link' => '/catalog']),
        homeBlock('banner', ['title' => 'Второй', 'image' => bannerImage('two')]),
    ]);

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->toContain('<picture>')
        ->and($html)->toContain('<source media="(max-width: 767px)"')
        ->and($html)->toContain('mob-800.webp 800w')
        ->and($html)->toContain('one-1600.webp 1600w')
        ->and($html)->toContain('width="1600" height="600"')
        ->and($html)->toContain('href="/catalog"')
        ->and(substr_count($html, 'fetchpriority="high"'))->toBe(1)
        ->and(substr_count($html, 'loading="lazy"'))->toBeGreaterThanOrEqual(1);

    // Only the first banner is eager.
    $firstImg = strpos($html, 'one-1600.webp');
    $secondImg = strpos($html, 'two-1600.webp');
    expect(substr($html, $firstImg, 400))->toContain('fetchpriority="high"')
        ->and(substr($html, $secondImg, 400))->toContain('loading="lazy"');
});

it('drops banners without a usable image and unsafe links', function () {
    storeHomeBlocks([
        homeBlock('banner', ['title' => 'Без картинки']),
        homeBlock('banner', ['title' => 'Опасная ссылка', 'image' => bannerImage('x'), 'button_text' => 'Жми', 'button_link' => 'javascript:alert(1)']),
    ]);

    $this->get(route('home'))
        ->assertDontSee('Без картинки')
        ->assertSee('Опасная ссылка')
        ->assertDontSee('javascript:', false)
        ->assertDontSee('Жми');
});

it('renders an accessible slider with autoplay settings', function () {
    storeHomeBlocks([homeBlock('slider', [
        'autoplay' => true,
        'interval' => 7,
        'slides' => [
            ['title' => 'Слайд 1', 'image' => bannerImage('s1')],
            ['title' => 'Слайд 2', 'image' => bannerImage('s2')],
        ],
    ])]);

    $this->get(route('home'))->assertOk()
        ->assertSee('aria-roledescription="carousel"', false)
        ->assertSee('aria-roledescription="slide"', false)
        ->assertSee('homeSlider({ autoplay: true, interval: 7000 })', false)
        ->assertSee('Слайд 1')->assertSee('Слайд 2');
});

it('renders advantages with inline svg icons', function () {
    storeHomeBlocks([homeBlock('advantages', ['items' => [
        ['icon' => 'truck', 'title' => 'Доставка', 'text' => 'По всей стране'],
        ['icon' => 'unknown', 'title' => 'Запасная иконка'],
    ]])]);

    $html = $this->get(route('home'))->assertOk()->assertSee('Доставка')->assertSee('По всей стране')->getContent();

    expect(substr_count($html, '<svg class="h-8 w-8"'))->toBe(2);
});

it('renders category tiles in auto and selected modes', function () {
    $a = Category::factory()->create(['name' => 'Авто А', 'sort_order' => 1]);
    $b = Category::factory()->create(['name' => 'Авто Б', 'sort_order' => 2]);
    $child = Category::factory()->childOf($a)->create(['name' => 'Вложенная']);

    storeHomeBlocks([homeBlock('categories', ['title' => 'Все', 'mode' => 'auto', 'max_count' => 1])]);
    expect(homeMain($this->get(route('home'))))->toContain('Авто А')->not->toContain('Авто Б');

    Cache::flush();
    storeHomeBlocks([homeBlock('categories', ['mode' => 'selected', 'categories' => [$child->id, $b->id], 'max_count' => 5])]);
    $main = homeMain($this->get(route('home')));
    expect($main)->toContain('Вложенная')->toContain('Авто Б')->not->toContain('Авто А')
        ->and(strpos($main, 'Вложенная'))->toBeLessThan(strpos($main, 'Авто Б'));
});

describe('products block', function () {
    function productsBlock(array $data): void
    {
        storeHomeBlocks([homeBlock('products', $data + ['title' => 'Витрина', 'limit' => 8, 'sort' => 'default', 'show_all' => true])]);
        Cache::flush();
    }

    it('selects hits, new and discounted products', function () {
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->featured()->create(['name' => 'Хитовый']);
        Product::factory()->inCategory($category)->isNew()->create(['name' => 'Новый']);
        Product::factory()->inCategory($category)->onSale()->create(['name' => 'Уценённый']);
        Product::factory()->inCategory($category)->create(['name' => 'Обычный']);
        Product::factory()->inCategory($category)->featured()->inactive()->create(['name' => 'Выключенный хит']);

        productsBlock(['source' => 'hits']);
        $this->get(route('home'))->assertSee('Хитовый')->assertDontSee('Новый')->assertDontSee('Уценённый')->assertDontSee('Обычный')->assertDontSee('Выключенный хит');

        productsBlock(['source' => 'new']);
        $this->get(route('home'))->assertSee('Новый')->assertDontSee('Хитовый')->assertDontSee('Обычный');

        productsBlock(['source' => 'discounted']);
        $this->get(route('home'))->assertSee('Уценённый')->assertDontSee('Обычный')->assertDontSee('Хитовый');
    });

    it('selects products of a category with its subcategories', function () {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        $other = Category::factory()->create();
        Product::factory()->inCategory($root)->create(['name' => 'В корне']);
        Product::factory()->inCategory($child)->create(['name' => 'В дочерней']);
        Product::factory()->inCategory($other)->create(['name' => 'В чужой']);

        productsBlock(['source' => 'category', 'category_id' => $root->id]);

        $this->get(route('home'))->assertSee('В корне')->assertSee('В дочерней')->assertDontSee('В чужой')
            ->assertSee(route('catalog.category', $root->slug), false);
    });

    it('keeps the manual selection order and ignores hidden products', function () {
        $category = Category::factory()->create();
        $a = Product::factory()->inCategory($category)->create(['name' => 'Альфа']);
        $b = Product::factory()->inCategory($category)->create(['name' => 'Браво']);
        $c = Product::factory()->inCategory($category)->create(['name' => 'Чарли']);
        $off = Product::factory()->inCategory($category)->inactive()->create(['name' => 'Выключен']);

        productsBlock(['source' => 'manual', 'product_ids' => [$c->id, $off->id, $a->id, $b->id]]);

        $this->get(route('home'))->assertSeeInOrder(['Чарли', 'Альфа', 'Браво'])->assertDontSee('Выключен');
    });

    it('limits and sorts', function () {
        $category = Category::factory()->create();
        foreach ([300, 100, 200, 400] as $i => $price) {
            Product::factory()->inCategory($category)->featured()->create(['name' => "Цена {$price}", 'base_price' => $price]);
        }

        productsBlock(['source' => 'hits', 'limit' => 2, 'sort' => 'price_asc']);
        $response = $this->get(route('home'))->assertSeeInOrder(['Цена 100', 'Цена 200'])->assertDontSee('Цена 300')->assertDontSee('Цена 400');
        expect(substr_count($response->getContent(), '<article'))->toBe(2);

        productsBlock(['source' => 'hits', 'limit' => 2, 'sort' => 'price_desc']);
        $this->get(route('home'))->assertSeeInOrder(['Цена 400', 'Цена 300'])->assertDontSee('Цена 100');
    });

    it('links to «Смотреть все» by source and hides the link when turned off', function () {
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->featured()->isNew()->onSale()->create();

        foreach (['hits' => 'hit', 'new' => 'new', 'discounted' => 'sale'] as $source => $flag) {
            productsBlock(['source' => $source]);
            $this->get(route('home'))->assertSee('Смотреть все')->assertSee(e(route('catalog.index', ['flags' => [$flag]])), false);
        }

        productsBlock(['source' => 'hits', 'show_all' => false]);
        $this->get(route('home'))->assertDontSee('Смотреть все');
    });

    it('follows the appearance grid columns', function () {
        $category = Category::factory()->create();
        Product::factory()->inCategory($category)->featured()->create();
        $appearance = app(AppearanceSettings::class);
        $appearance->grid_columns = 4;
        $appearance->save();
        productsBlock(['source' => 'hits']);

        expect(homeMain($this->get(route('home'))))->toContain('md:gap-4 lg:grid-cols-4');

        $appearance->grid_columns = 3;
        $appearance->save();

        expect(homeMain($this->get(route('home'))))->toContain('md:gap-4 lg:grid-cols-3');
    });
});

describe('cache', function () {
    it('serves the second request from the cache', function () {
        $category = Category::factory()->create();
        Product::factory()->count(5)->inCategory($category)->featured()->create();
        storeHomeBlocks([homeBlock('products', ['source' => 'hits', 'limit' => 8, 'sort' => 'default'])]);
        Cache::flush();

        DB::enableQueryLog();
        $this->get(route('home'))->assertOk();
        $cold = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->get(route('home'))->assertOk();
        $warm = count(DB::getQueryLog());

        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeTrue()
            ->and($warm)->toBeLessThan($cold);
    });

    it('keeps the cold home page under 30 queries', function () {
        $category = Category::factory()->create();
        Product::factory()->count(12)->inCategory($category)->featured()->create();
        storeHomeBlocks([
            homeBlock('banner', ['image' => bannerImage()]),
            homeBlock('categories', ['mode' => 'auto', 'max_count' => 8]),
            homeBlock('products', ['source' => 'hits', 'limit' => 12]),
            homeBlock('products', ['source' => 'new', 'limit' => 12]),
            homeBlock('advantages', ['items' => [['icon' => 'star', 'title' => 'T']]]),
        ]);
        Cache::flush();

        DB::enableQueryLog();
        $this->get(route('home'))->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(30);
    });

    it('is dropped when the builder, a product or a category changes', function () {
        $category = Category::factory()->create(['name' => 'Исходная']);
        $product = Product::factory()->inCategory($category)->featured()->create(['name' => 'Исходный']);
        storeHomeBlocks([homeBlock('products', ['source' => 'hits', 'limit' => 8, 'sort' => 'default'])]);
        Cache::flush();

        $this->get(route('home'))->assertSee('Исходный');
        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeTrue();

        // Builder
        storeHomeBlocks([homeBlock('text', ['title' => 'Из конструктора', 'content' => '<p>x</p>'])]);
        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeFalse();
        $this->get(route('home'))->assertSee('Из конструктора');

        // Product
        storeHomeBlocks([homeBlock('products', ['source' => 'hits', 'limit' => 8, 'sort' => 'default'])]);
        $this->get(route('home'))->assertSee('Исходный');
        $product->update(['name' => 'Переименованный']);
        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeFalse();
        $this->get(route('home'))->assertSee('Переименованный');

        // Product deleted
        $product->delete();
        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeFalse();

        // Category
        $this->get(route('home'));
        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeTrue();
        $category->update(['is_active' => false]);
        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeFalse();
    });

    it('is dropped when appearance settings change', function () {
        $this->get(route('home'));
        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeTrue();

        $appearance = app(AppearanceSettings::class);
        $appearance->per_page = 30;
        $appearance->save();

        expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeFalse();
    });

    it('expires after ten minutes', function () {
        storeHomeBlocks([homeBlock('text', ['title' => 'Старое', 'content' => '<p>x</p>'])]);
        Cache::flush();
        $this->get(route('home'))->assertSee('Старое');

        // Written behind the cache's back (raw DB), so no listener fires.
        DB::table('settings')->where('group', 'home_page')->where('name', 'blocks')
            ->update(['payload' => json_encode([homeBlock('text', ['title' => 'Новое', 'content' => '<p>x</p>'])])]);
        app()->forgetInstance(HomePageSettings::class);

        $this->get(route('home'))->assertSee('Старое');

        $this->travel(11)->minutes();
        $this->get(route('home'))->assertSee('Новое');
    });
});

it('gives every session its own csrf token in the cached add-to-cart forms', function () {
    $category = Category::factory()->create();
    Product::factory()->inCategory($category)->featured()->create(['stock_qty' => 5]);
    storeHomeBlocks([homeBlock('products', ['source' => 'hits', 'limit' => 4, 'sort' => 'default'])]);
    Cache::flush();

    $first = $this->withSession(['_token' => 'token-of-first-session-0000000000000000'])->get(route('home'))->assertOk();
    $first->assertSee('name="_token" value="token-of-first-session-0000000000000000"', false);
    expect(Cache::has(HomePageRenderer::CACHE_KEY))->toBeTrue()
        ->and(Cache::get(HomePageRenderer::CACHE_KEY))->not->toContain('token-of-first-session');

    $this->flushSession();
    $second = $this->withSession(['_token' => 'token-of-second-session-111111111111111'])->get(route('home'))->assertOk();
    $second->assertSee('name="_token" value="token-of-second-session-111111111111111"', false)
        ->assertDontSee('token-of-first-session', false);
});

it('lets a different session add to the cart from the cached home page', function () {
    $category = Category::factory()->create();
    $product = Product::factory()->inCategory($category)->featured()->create(['stock_qty' => 5]);
    storeHomeBlocks([homeBlock('products', ['source' => 'hits', 'limit' => 4, 'sort' => 'default'])]);
    Cache::flush();

    $this->get(route('home'))->assertOk();
    $this->flushSession();

    $token = 'second-session-token-2222222222222222222';
    $html = $this->withSession(['_token' => $token])->get(route('home'))->getContent();
    expect($html)->toContain('value="'.$token.'"');

    $this->withSession(['_token' => $token])
        ->post(route('cart.add'), ['_token' => $token, 'product_id' => $product->id])
        ->assertStatus(302);
});
