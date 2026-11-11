<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Models\Category;
use App\Models\Product;
use App\Services\Catalog\AttributeFilterParser;
use App\Services\Catalog\CatalogFilters;
use App\Services\Catalog\CatalogQuery;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryTree;
use App\Services\Catalog\FacetService;
use Illuminate\Support\Facades\DB;

/**
 * NF-02, FL-10, FL-12, 20.8: query budget and facet timing on a seeded catalog.
 *
 * Default size: 2 000 products x 8 characteristics. The 20 000 x 8 check of TZ 20.8 runs the same test on a big catalog:
 *
 *     FACET_PERF_PRODUCTS=20000 php artisan test --filter=FacetPerformanceTest
 *     FACET_PERF_PRODUCTS=20000 DB_CONNECTION=mysql DB_DATABASE=shop_test DB_USERNAME=shop DB_PASSWORD=shop php artisan test --filter=FacetPerformanceTest
 *
 * `FACET_PERF_MAX_MS` (default 100, FL-12) is the allowed time of one facet query. Measured on MySQL 8.0 at 20 000 x 8: numeric
 * ~15 ms, list facets 33-51 ms (before the selection became a derived table: ~210 and 52-65 ms).
 * `FACET_PERF_EXPLAIN=1` prints the MySQL plan of every facet query to stderr.
 */
beforeEach(function (): void {
    $this->withoutVite();
    attrMigrated();

    $this->total = max(100, (int) (getenv('FACET_PERF_PRODUCTS') ?: 2000));
    $this->root = Category::factory()->create(['slug' => 'big']);
    $children = Category::factory()->count(4)->childOf($this->root)->create();
    $categoryIds = [$this->root->id, ...$children->pluck('id')->all()];

    // 5 list characteristics (4-12 values), 3 numbers, 1 boolean: a set of 9 filters.
    $attributes = [];
    $values = [];
    foreach ([['material', 8], ['color', 12], ['season', 4], ['country', 10], ['style', 6]] as [$slug, $count]) {
        $attributes[$slug] = facetAttr($slug);
        foreach (range(1, $count) as $n) {
            $values[$slug][] = facetValue($attributes[$slug], $slug.'-'.$n, ucfirst($slug).' '.$n, $n)->id;
        }
    }
    $numbers = ['power' => facetAttr('power', AttributeType::Number), 'weight' => facetAttr('weight', AttributeType::Number), 'width' => facetAttr('width', AttributeType::Number)];
    $flag = facetAttr('waterproof', AttributeType::Boolean);
    facetSet($this->root, [...array_values($attributes), ...array_values($numbers), $flag]);

    // Bulk insert: one real product is the template of every row.
    $template = Product::factory()->inCategory($this->root)->create(['name' => 'Шаблон']);
    $row = (array) DB::table('products')->where('id', $template->id)->first();
    unset($row['id']);
    $row['attributes'] = null;
    $first = $template->id;

    mt_srand(42);
    $products = [];
    for ($i = 1; $i <= $this->total; $i++) {
        $products[] = [...$row, 'sku' => 'PERF-'.$i, 'slug' => 'perf-'.$i, 'name' => 'Товар '.$i, 'category_id' => $categoryIds[$i % 5]];
    }
    foreach (array_chunk($products, 200) as $chunk) {
        DB::table('products')->insert($chunk);
    }
    $ids = DB::table('products')->where('id', '>', $first)->orderBy('id')->pluck('id')->all();

    $rows = [];
    foreach ($ids as $id) {
        foreach ($values as $slug => $valueIds) {
            $rows[] = ['product_id' => $id, 'attribute_id' => $attributes[$slug]->id, 'attribute_value_id' => $valueIds[mt_rand(0, count($valueIds) - 1)], 'value_number' => null, 'value_bool' => null];
        }
        foreach ($numbers as $attribute) {
            $rows[] = ['product_id' => $id, 'attribute_id' => $attribute->id, 'attribute_value_id' => null, 'value_number' => (string) mt_rand(1, 5000), 'value_bool' => null];
        }
        // 8 characteristics per product: the boolean is set on a third of them, the rest has 8 values (5 lists + 3 numbers).
        if ($id % 3 === 0) {
            $rows[] = ['product_id' => $id, 'attribute_id' => $flag->id, 'attribute_value_id' => null, 'value_number' => null, 'value_bool' => true];
        }
    }
    foreach (array_chunk($rows, 1000) as $chunk) {
        DB::table('product_attribute_values')->insert($chunk);
    }

    $this->values = $values;
    $this->attributes = $attributes;
});

/**
 * @return array{queries: int, facet: list<array{sql: string, ms: float}>}
 */
function profileRequest(string $url): array
{
    $facet = [];
    $queries = 0;

    DB::listen(function ($query) use (&$facet, &$queries): void {
        if (str_starts_with($query->sql, 'explain')) {
            return;
        }

        $queries++;

        if (str_contains($query->sql, 'product_attribute_values') && str_contains(strtolower($query->sql), 'group by')) {
            $facet[] = ['sql' => $query->sql, 'ms' => $query->time];

            // FL-12: `FACET_PERF_EXPLAIN=1` prints the plan of every facet query (MySQL).
            if (getenv('FACET_PERF_EXPLAIN') && DB::getDriverName() === 'mysql') {
                fwrite(STDERR, "\n".sprintf('%.1f ms: ', $query->time).$query->sql."\n".json_encode(DB::select('explain '.$query->sql, $query->bindings), JSON_UNESCAPED_UNICODE)."\n");
            }
        }
    });

    test()->get($url)->assertOk();

    if (getenv('FACET_PERF_EXPLAIN')) {
        fwrite(STDERR, sprintf("\n%d SQL queries, %d facet queries: %s\n", $queries, count($facet), $url));
    }

    return ['queries' => $queries, 'facet' => $facet];
}

it('stays within 30 queries and 10 facet queries on a category page with 9 filters (NF-02, FL-10)', function (): void {
    $profile = profileRequest(route('catalog.category', 'big'));

    // 5 list queries + 1 numeric query (the boolean needs none).
    expect($profile['queries'])->toBeLessThanOrEqual(30)
        ->and(count($profile['facet']))->toBe(6)
        ->and(count($profile['facet']))->toBeLessThanOrEqual(10);
});

it('stays within 30 queries with filters applied, cached facets and a second visit (NF-02, FL-11)', function (): void {
    $url = route('catalog.category', 'big').'?'.http_build_query(['a' => [
        'material' => ['material-1', 'material-2'],
        'color' => ['color-3'],
        'power' => ['min' => '100', 'max' => '4000'],
        'waterproof' => '1',
    ]]);

    $first = profileRequest($url);
    $second = profileRequest($url);

    expect($first['queries'])->toBeLessThanOrEqual(30)
        ->and(count($first['facet']))->toBeLessThanOrEqual(10)
        // The facets come from the cache the second time: only the listing queries remain.
        ->and(count($second['facet']))->toBe(0)
        ->and($second['queries'])->toBeLessThan($first['queries']);
});

it('answers every facet query in time and keeps listing and counters consistent (FL-12)', function (): void {
    $max = (float) (getenv('FACET_PERF_MAX_MS') ?: 100);
    $url = route('catalog.category', 'big').'?a[material][]=material-1&a[season][]=season-2';

    // The first run warms the database buffers; a new catalog version makes the facets be built again.
    profileRequest($url);
    CatalogVersion::bump();
    $profile = profileRequest($url);

    if (getenv('FACET_PERF_EXPLAIN')) {
        fwrite(STDERR, "\nwarm facet times, ms: ".implode(', ', array_map(fn (array $query): string => sprintf('%.1f', $query['ms']), $profile['facet']))."\n");
    }

    foreach ($profile['facet'] as $query) {
        expect($query['ms'])->toBeLessThan($max, $query['sql']);
    }

    // The counter of a value equals the size of the listing after selecting it (the other filters stay).
    $filters = (new CatalogFilters);
    $selection = app(AttributeFilterParser::class)->parse(['a' => ['material' => ['material-1'], 'season' => ['season-2']]], $this->root->id);
    $filters = $filters->withAttributes($selection);
    $groups = collect(app(FacetService::class)->forCategory($this->root->id, app(CategoryTree::class)->descendantIds($this->root->id), $filters))->keyBy('slug');
    $color = collect($groups['color']->values)->first(fn ($value) => $value->count > 0);

    $withColor = $filters->withAttributes(app(AttributeFilterParser::class)->parse(['a' => ['material' => ['material-1'], 'season' => ['season-2'], 'color' => [$color->slug]]], $this->root->id));
    $total = app(CatalogQuery::class)->selection($withColor, app(CategoryTree::class)->descendantIds($this->root->id))->count();

    expect($total)->toBe($color->count);
})->group('perf');

it('stays within 30 queries on a worst-case page: nine list filters, none cached (NF-02, FL-10)', function (): void {
    $category = Category::factory()->create(['slug' => 'wide']);
    $attributes = [];

    foreach (range(1, 9) as $i) {
        $attribute = facetAttr('wide'.$i);
        $attributes[] = $attribute;
        $ids = array_map(fn (int $n): int => facetValue($attribute, 'w'.$i.'-'.$n, 'W'.$i.' '.$n, $n)->id, range(1, 4));

        foreach (Product::factory()->count(30)->inCategory($category)->create() as $n => $product) {
            DB::table('product_attribute_values')->insert(['product_id' => $product->id, 'attribute_id' => $attribute->id, 'attribute_value_id' => $ids[$n % 4], 'value_number' => null, 'value_bool' => null]);
        }
    }

    facetSet($category, $attributes);

    $profile = profileRequest(route('catalog.category', 'wide').'?a[wide1][]=w1-1&a[wide2][]=w2-1&a[wide3][]=w3-1');

    expect($profile['facet'])->toHaveCount(9)
        ->and($profile['queries'])->toBeLessThanOrEqual(30);
});
