<?php

declare(strict_types=1);

use App\Enums\CategoryAccessMode;
use App\Jobs\GenerateSitemap;
use App\Models\Category;
use App\Models\CustomerGroup;
use App\Models\Page;
use App\Models\Product;
use App\Services\Access\CustomerGroupService;
use App\Services\Seo\SitemapGenerator;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

/**
 * CG-15, TZ 25.8: changes that decide what the «Гость» group sees queue a sitemap rebuild, so hidden URLs do not wait
 * for the nightly run.
 */
beforeEach(function (): void {
    $this->withoutVite();
    config(['app.url' => 'https://shop.example', 'shop.sitemap_rebuild_on_change' => true]);
    $this->dir = storage_path('framework/testing/sitemap-'.uniqid());
    File::ensureDirectoryExists($this->dir);
    config(['shop.sitemap_dir' => $this->dir]);
    Page::query()->delete();

    $this->open = Category::factory()->create(['slug' => 'otkrytaya']);
    $this->secret = Category::factory()->create(['slug' => 'sekretnaya']);
    $this->openProduct = groupProduct($this->open, 'Открытый', ['slug' => 'open-product']);
    $this->secretProduct = groupProduct($this->secret, 'Секретный', ['slug' => 'secret-product']);
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

it('queues a rebuild when the guest group hides a category, and the rebuilt sitemap omits it (CG-15)', function (): void {
    app(SitemapGenerator::class)->generate();
    expect(file_get_contents($this->dir.'/sitemap.xml'))->toContain('sekretnaya')->toContain('secret-product');

    Queue::fake();

    app(CustomerGroupService::class)->save(
        guestGroup(),
        ['category_access_mode' => CategoryAccessMode::AllExcept],
        [$this->secret->id],
    );

    Queue::assertPushed(GenerateSitemap::class);

    // The job reads the data after the change.
    (new GenerateSitemap)->handle(app(SitemapGenerator::class));
    $xml = file_get_contents($this->dir.'/sitemap.xml');

    expect($xml)->not->toContain('sekretnaya')
        ->not->toContain('secret-product')
        ->toContain('otkrytaya')
        ->toContain('open-product');
});

it('queues a rebuild when a customer group is deleted (CG-15)', function (): void {
    $group = CustomerGroup::factory()->create();
    Queue::fake();

    app(CustomerGroupService::class)->delete($group);

    Queue::assertPushed(GenerateSitemap::class);
});

it('queues a rebuild when a page is hidden from the guest, deactivated or deleted (CG-10, CG-15)', function (): void {
    $retail = CustomerGroup::retail();
    $page = Page::factory()->create(['slug' => 'public-page']);
    Queue::fake();

    $page->update(['visible_group_ids' => [$retail->id]]);
    Queue::assertPushed(GenerateSitemap::class, 1);

    Queue::fake();
    $page->update(['visible_group_ids' => null, 'is_active' => false]);
    Queue::assertPushed(GenerateSitemap::class, 1);

    Queue::fake();
    $page->delete();
    Queue::assertPushed(GenerateSitemap::class, 1);
});

it('queues a rebuild when a category or product leaves the guest storefront (CG-15)', function (): void {
    Queue::fake();
    $this->secret->update(['is_active' => false]);
    Queue::assertPushed(GenerateSitemap::class, 1);

    Queue::fake();
    $this->openProduct->update(['is_active' => false]);
    Queue::assertPushed(GenerateSitemap::class, 1);

    Queue::fake();
    $this->secretProduct->update(['category_id' => $this->open->id]);
    Queue::assertPushed(GenerateSitemap::class, 1);

    Queue::fake();
    $this->secretProduct->delete();
    Queue::assertPushed(GenerateSitemap::class, 1);
});

it('does not queue a rebuild for changes that do not affect the URLs or for new records', function (): void {
    Queue::fake();

    $this->openProduct->update(['name' => 'Переименованный']);
    Product::factory()->inCategory($this->open)->create();
    Category::factory()->create();
    Page::factory()->create();

    Queue::assertNotPushed(GenerateSitemap::class);
});

it('is unique until it starts, so a burst of changes queues one job', function (): void {
    expect(new GenerateSitemap)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
});
