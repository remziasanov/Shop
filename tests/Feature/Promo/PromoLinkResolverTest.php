<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\PromoLink;
use App\Services\Promo\BotDetector;
use App\Services\Promo\PromoLinkResolver;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->resolver = app(PromoLinkResolver::class);
});

it('leads to a product, a variant, a category, a filtered catalog and a page (PM-21)', function (): void {
    $product = sellable(['slug' => 'kurtka']);
    $size = optionWith('Размер', ['S']);
    $card = variantCard(['slug' => 'platie'], $size);
    $variant = variantOf($card, [$size->values[0]], ['stock_qty' => 3]);
    $category = Category::factory()->create(['slug' => 'obuv']);

    expect($this->resolver->resolve(PromoLink::factory()->toProduct($product)->create())->url)->toBe('/product/kurtka')
        ->and($this->resolver->resolve(PromoLink::factory()->toVariant($variant)->create())->url)->toBe('/product/platie?variant='.$variant->id)
        ->and($this->resolver->resolve(PromoLink::factory()->toCategory($category)->create())->url)->toBe('/catalog/obuv')
        ->and($this->resolver->resolve(PromoLink::factory()->toCatalogFilter('/catalog/obuv?sort=price_asc&brand=nike')->create())->url)->toBe('/catalog/obuv?sort=price_asc&brand=nike')
        ->and($this->resolver->resolve(PromoLink::factory()->toUrl('/page/delivery')->create())->url)->toBe('/page/delivery');
});

it('refuses external addresses and every open redirect trick (PM-22)', function (): void {
    foreach (['https://evil.example/x', 'http://evil.example', '//evil.example', '/\\evil.example', '\\\\evil.example', 'javascript:alert(1)', 'evil.example/page', '', '/ok path', "/ok\r\nLocation: http://evil.example", "/\tevil", ' /page', '///evil.example'] as $url) {
        $link = PromoLink::factory()->toUrl($url)->create();

        expect(PromoLinkResolver::isInternalPath($url))->toBeFalse($url)
            ->and($this->resolver->resolve($link)->ok)->toBeFalse($url)
            ->and($this->resolver->resolve($link)->reason)->toBe('target_invalid');
    }

    foreach (['/', '/catalog', '/page/about?x=1', '/catalog/obuv?brand=a&sort=b', '/%2F%2Fevil.example'] as $url) {
        expect(PromoLinkResolver::isInternalPath($url))->toBeTrue($url);
    }

    // A catalog filter must stay in the catalog, and an external one is refused as well.
    expect($this->resolver->resolve(PromoLink::factory()->toCatalogFilter('/page/about')->create())->ok)->toBeFalse()
        ->and($this->resolver->resolve(PromoLink::factory()->toCatalogFilter('https://evil.example/catalog')->create())->ok)->toBeFalse()
        ->and($this->resolver->resolve(PromoLink::factory()->toCatalogFilter('/catalogue')->create())->ok)->toBeFalse();
});

it('is stale when inactive or outside its period (PM-22)', function (): void {
    $now = Carbon::parse('2026-06-10 12:00:00');

    $inactive = PromoLink::factory()->inactive()->create();
    $early = PromoLink::factory()->create(['starts_at' => '2026-06-11 00:00:00']);
    $late = PromoLink::factory()->create(['ends_at' => '2026-06-09 23:59:59']);
    $fine = PromoLink::factory()->create(['starts_at' => '2026-06-01 00:00:00', 'ends_at' => '2026-06-30 23:59:59']);

    expect($this->resolver->resolve($inactive, null, $now)->reason)->toBe('inactive')
        ->and($this->resolver->resolve($early, null, $now)->reason)->toBe('not_started')
        ->and($this->resolver->resolve($late, null, $now)->reason)->toBe('expired')
        ->and($this->resolver->resolve($fine, null, $now)->ok)->toBeTrue()
        ->and($this->resolver->resolve($early, null, $now)->message())->toBe('Ссылка устарела');
});

it('is stale when the target is unavailable: inactive or deleted product, inactive variant, hidden category (PM-22)', function (): void {
    $product = sellable(['slug' => 'a']);
    $link = PromoLink::factory()->toProduct($product)->create();

    expect($this->resolver->resolve($link)->ok)->toBeTrue();

    $product->update(['is_active' => false]);

    expect($this->resolver->resolve($link)->reason)->toBe('target_unavailable');

    $product->update(['is_active' => true]);
    $product->delete();

    expect($this->resolver->resolve($link)->reason)->toBe('target_unavailable');

    $size = optionWith('Размер', ['S', 'M']);
    $card = variantCard([], $size);
    $variant = variantOf($card, [$size->values[0]], ['stock_qty' => 3]);
    variantOf($card, [$size->values[1]], ['stock_qty' => 3]);
    $variantLink = PromoLink::factory()->toVariant($variant)->create();

    expect($this->resolver->resolve($variantLink)->ok)->toBeTrue();

    $variant->forceFill(['is_active' => false])->save();

    expect($this->resolver->resolve($variantLink->refresh())->reason)->toBe('target_unavailable');

    // A variant of another card does not count.
    $other = sellable();
    $stray = PromoLink::factory()->toVariant($variant)->create(['target_id' => $other->id]);
    $variant->forceFill(['is_active' => true])->save();

    expect($this->resolver->resolve($stray)->reason)->toBe('target_unavailable');

    $category = Category::factory()->create();
    $categoryLink = PromoLink::factory()->toCategory($category)->create();

    expect($this->resolver->resolve($categoryLink)->ok)->toBeTrue();

    $category->update(['is_active' => false]);

    expect($this->resolver->resolve($categoryLink)->reason)->toBe('target_unavailable');
});

it('checks the category and the product against the viewer group (PM-22, CG-14)', function (): void {
    $open = Category::factory()->create(['slug' => 'open']);
    $closed = Category::factory()->create(['slug' => 'closed']);
    $hidden = groupProduct($closed, 'Скрытый');
    $group = CustomerGroup::factory()->create(['name' => 'Опт']);
    configureGuest(['name' => 'Гость'], CategoryAccessMode::Only, [$open]);

    $guestCategory = PromoLink::factory()->toCategory($closed)->create();
    $guestProduct = PromoLink::factory()->toProduct($hidden)->create();
    $okCategory = PromoLink::factory()->toCategory($open)->create();

    // The guest sees only «open»; the wholesale group (all categories) sees both.
    expect($this->resolver->resolve($guestCategory)->reason)->toBe('target_unavailable')
        ->and($this->resolver->resolve($guestProduct)->reason)->toBe('target_unavailable')
        ->and($this->resolver->resolve($okCategory)->ok)->toBeTrue()
        ->and($this->resolver->resolve($guestCategory, $group)->ok)->toBeTrue()
        ->and($this->resolver->resolve($guestProduct, $group)->ok)->toBeTrue();
});

it('adds UTM labels only when the address has none of them yet (PM-27)', function (): void {
    $link = PromoLink::factory()->toUrl('/catalog?utm_source=mail&sort=price')->create([
        'utm_source' => 'telegram', 'utm_medium' => 'cpc', 'utm_campaign' => 'spring-2026', 'utm_term' => null, 'utm_content' => '',
    ]);

    // utm_source is already in the address and stays; the others are appended; empty ones are skipped.
    expect($this->resolver->resolve($link)->url)->toBe('/catalog?utm_source=mail&sort=price&utm_medium=cpc&utm_campaign=spring-2026');

    $plain = PromoLink::factory()->toUrl('/page/about')->create(['utm_source' => 'vk']);

    expect($this->resolver->resolve($plain)->url)->toBe('/page/about?utm_source=vk')
        ->and($this->resolver->resolve(PromoLink::factory()->toUrl('/page/about#team')->create(['utm_source' => 'vk']))->url)->toBe('/page/about?utm_source=vk#team')
        ->and($this->resolver->resolve(PromoLink::factory()->toUrl('/page/about')->create())->url)->toBe('/page/about');
});

it('lets only Latin letters, digits and ._- through in UTM values, at most 100 characters (PM-27)', function (): void {
    expect(PromoLinkResolver::sanitizeUtm('Spring Sale 2026!'))->toBe('SpringSale2026')
        ->and(PromoLinkResolver::sanitizeUtm('a.b_c-d'))->toBe('a.b_c-d')
        ->and(PromoLinkResolver::sanitizeUtm('весна&utm_x=1'))->toBe('utm_x1')
        ->and(PromoLinkResolver::sanitizeUtm('<script>'))->toBe('script')
        ->and(strlen(PromoLinkResolver::sanitizeUtm(str_repeat('a', 150))))->toBe(100);

    $link = PromoLink::factory()->toUrl('/catalog')->create(['utm_source' => 'a b&c=d#e', 'utm_medium' => 'ы']);

    // The dangerous characters are gone, an all-invalid value is not added at all.
    expect($this->resolver->resolve($link)->url)->toBe('/catalog?utm_source=abcde');
});

it('recognizes bots by the User-Agent (PM-25)', function (): void {
    foreach ([
        null, '', '   ',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
        'Mozilla/5.0 (compatible; bingbot/2.0)',
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'TelegramBot (like TwitterBot)', 'WhatsApp/2.23.20 A', 'Twitterbot/1.0', 'Slackbot-LinkExpanding 1.0', 'Mozilla/5.0 (compatible; vkShare; +http://vk.com/dev/Share)',
        'curl/8.4.0', 'python-requests/2.31', 'Go-http-client/1.1', 'Mozilla/5.0 AppleWebKit HeadlessChrome/120.0',
    ] as $ua) {
        expect(BotDetector::isBot($ua))->toBeTrue((string) $ua);
    }

    foreach ([
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        'Pest',
    ] as $ua) {
        expect(BotDetector::isBot($ua))->toBeFalse($ua);
    }
});

it('checks the category of a catalog filter link, while plain /catalog stays available (PM-22)', function (): void {
    $category = Category::factory()->create(['slug' => 'obuv']);
    $filter = PromoLink::factory()->toCatalogFilter('/catalog/obuv?sort=price_asc')->create();
    $plain = PromoLink::factory()->toCatalogFilter('/catalog?sort=price_asc')->create();
    $unknown = PromoLink::factory()->toCatalogFilter('/catalog/net-takoy?brand=nike')->create();

    expect($this->resolver->resolve($filter)->ok)->toBeTrue();

    $category->update(['is_active' => false]);

    expect($this->resolver->resolve($filter)->reason)->toBe('target_unavailable')
        ->and($this->resolver->resolve($plain)->ok)->toBeTrue()
        ->and($this->resolver->resolve($unknown)->reason)->toBe('target_unavailable');
});

it('checks the category of a catalog filter link against the viewer group (PM-22, CG-14)', function (): void {
    $open = Category::factory()->create(['slug' => 'open']);
    $closed = Category::factory()->create(['slug' => 'closed']);
    $group = CustomerGroup::factory()->create(['name' => 'Опт']);
    configureGuest(['name' => 'Гость'], CategoryAccessMode::Only, [$open]);

    $link = PromoLink::factory()->toCatalogFilter('/catalog/closed?sort=price_asc')->create();

    expect($this->resolver->resolve($link)->reason)->toBe('target_unavailable')
        ->and($this->resolver->resolve($link, $group)->ok)->toBeTrue()
        ->and($this->resolver->resolve(PromoLink::factory()->toCatalogFilter('/catalog/open?x=1')->create())->ok)->toBeTrue();
});
