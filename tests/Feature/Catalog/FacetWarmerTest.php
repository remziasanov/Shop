<?php

declare(strict_types=1);

use App\Jobs\WarmCategoryFacets;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\FacetWarmer;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * FL-11, FL-12 (D-164): the facets of a category page without filters are built in the queue, so the first visitor
 * does not wait for them.
 */
beforeEach(function (): void {
    $this->withoutVite();
    attrMigrated();

    $this->category = Category::factory()->create(['slug' => 'warm']);
    $color = facetAttr('color');
    $red = facetValue($color, 'red', 'Red');
    $product = Product::factory()->inCategory($this->category)->create();
    DB::table('product_attribute_values')->insert(['product_id' => $product->id, 'attribute_id' => $color->id, 'attribute_value_id' => $red->id, 'value_number' => null, 'value_bool' => null]);
    facetSet($this->category, [$color]);
});

function facetQueriesOf(string $url): int
{
    $count = 0;

    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'product_attribute_values') && str_contains(strtolower($query->sql), 'group by')) {
            $count++;
        }
    });

    test()->get($url)->assertOk();

    return $count;
}

it('builds the unfiltered facets so that the page reads them from the cache', function (): void {
    expect(app(FacetWarmer::class)->warm())->toBeNull();

    expect(facetQueriesOf(route('catalog.category', 'warm')))->toBe(0);
});

it('leaves the facets cold without warming (the control of the test above)', function (): void {
    expect(facetQueriesOf(route('catalog.category', 'warm')))->toBeGreaterThan(0);
});

it('stops at the budget and continues from the returned offset', function (): void {
    $warmer = app(FacetWarmer::class);

    expect($warmer->warm(0, 0.0))->toBe(0)
        ->and($warmer->warm(0, 30.0))->toBeNull();
});

it('does not warm categories the group cannot see, nor when the facet cache is off', function (): void {
    $this->category->update(['is_active' => false]);
    expect(app(FacetWarmer::class)->pairs())->toBe([]);

    $this->category->update(['is_active' => true]);
    $settings = app(CatalogSettings::class);
    $settings->facets_cache_minutes = 0;
    $settings->save();

    expect(app(FacetWarmer::class)->warm())->toBeNull()
        ->and(facetQueriesOf(route('catalog.category', 'warm')))->toBeGreaterThan(0);
});

it('queues one warming job from the command and the job warms the cache', function (): void {
    Queue::fake();
    $this->artisan('shop:warm-facets')->assertSuccessful();
    Queue::assertPushed(WarmCategoryFacets::class, 1);

    app()->call([new WarmCategoryFacets, 'handle']);

    expect(facetQueriesOf(route('catalog.category', 'warm')))->toBe(0);
});
