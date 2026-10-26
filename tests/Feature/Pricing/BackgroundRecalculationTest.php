<?php

declare(strict_types=1);

use App\Console\Commands\RecalcPricesCommand;
use App\Jobs\RecalculatePrices;
use App\Livewire\Admin\PriceRecalcBanner;
use App\Models\PriceRule;
use App\Models\Product;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Services\Pricing\ProductPriceUpdater;
use App\Services\Pricing\RecalculationProgress;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\artisan;

/**
 * @return list<int>
 */
function productIds(int $count, array $attributes = []): array
{
    return Product::factory()->count($count)->create($attributes + ['base_price' => '1000.00'])->pluck('id')->all();
}

function forceFinalPrice(string $price): void
{
    DB::table('products')->update(['final_price' => $price]);
}

describe('RecalculationProgress', function (): void {
    it('tracks done and total and clears when finished', function (): void {
        $progress = app(RecalculationProgress::class);

        expect($progress->current())->toBeNull();

        $progress->add(10);
        $progress->advance(3);

        expect($progress->current())->toBe(['total' => 10, 'done' => 3, 'percent' => 30]);

        $progress->add(5);
        expect($progress->current()['total'])->toBe(15);

        $progress->advance(12);

        expect($progress->current())->toBeNull()
            ->and(Cache::has(RecalculationProgress::CACHE_KEY))->toBeFalse();
    });

    it('ignores advancing when nothing is running', function (): void {
        app(RecalculationProgress::class)->advance(5);

        expect(app(RecalculationProgress::class)->current())->toBeNull();
    });
});

describe('RecalculatePrices job', function (): void {
    it('recalculates the products and advances the progress', function (): void {
        $ids = productIds(3);
        PriceRule::factory()->discountPercent(10)->create();
        forceFinalPrice('1.00');
        app(RecalculationProgress::class)->add(5);

        (new RecalculatePrices($ids))->handle(app(ProductPriceUpdater::class), app(RecalculationProgress::class));

        expect(Product::query()->pluck('final_price')->unique()->all())->toBe(['900.00'])
            ->and(app(RecalculationProgress::class)->current())->toBe(['total' => 5, 'done' => 3, 'percent' => 60]);
    });

    it('is idempotent', function (): void {
        $ids = productIds(2);
        PriceRule::factory()->discountPercent(10)->create();

        dispatch_sync(new RecalculatePrices($ids));
        dispatch_sync(new RecalculatePrices($ids));

        expect(Product::query()->pluck('final_price')->unique()->all())->toBe(['900.00']);
    });

    it('has queue limits within the Beget budget', function (): void {
        $job = new RecalculatePrices([1]);

        expect($job->tries)->toBe(3)
            ->and($job->timeout)->toBeLessThanOrEqual(40)
            ->and($job->backoff())->not->toBeEmpty();
    });

    it('finishes the progress when it fails for good', function (): void {
        app(RecalculationProgress::class)->add(2);

        (new RecalculatePrices([1, 2]))->failed(new RuntimeException('boom'));

        expect(app(RecalculationProgress::class)->current())->toBeNull();
    });
});

describe('ruleChanged', function (): void {
    it('queues ceil(n / 500) jobs with the right id chunks and registers the total', function (): void {
        Queue::fake();
        $ids = productIds(1001);
        $rule = PriceRule::factory()->discountPercent(10)->create();

        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));

        Queue::assertPushed(RecalculatePrices::class, 3);
        $chunks = Queue::pushed(RecalculatePrices::class)->map(fn (RecalculatePrices $job): array => $job->productIds)->all();
        sort($ids);

        expect($chunks)->toBe(array_chunk($ids, 500))
            ->and(app(RecalculationProgress::class)->current())->toBe(['total' => 1001, 'done' => 0, 'percent' => 0]);
    });

    it('queues nothing when no product is affected', function (): void {
        Queue::fake();
        $rule = PriceRule::factory()->discountPercent(10)->create();

        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));

        Queue::assertNothingPushed();
        expect(app(RecalculationProgress::class)->current())->toBeNull();
    });

    it('updates final prices with the sync queue and leaves no progress behind', function (): void {
        productIds(2);
        $rule = PriceRule::factory()->discountPercent(10)->create();

        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));

        expect(Product::query()->pluck('final_price')->unique()->all())->toBe(['900.00'])
            ->and(app(RecalculationProgress::class)->current())->toBeNull();
    });
});

describe('shop:recalc-prices', function (): void {
    beforeEach(fn () => @unlink(RecalcPricesCommand::lastRunPath()));
    afterEach(fn () => @unlink(RecalcPricesCommand::lastRunPath()));

    it('requires exactly one of --all and --boundaries', function (): void {
        artisan('shop:recalc-prices')->assertExitCode(2);
        artisan('shop:recalc-prices', ['--all' => true, '--boundaries' => true])->assertExitCode(2);
    });

    it('--all queues chunks of all non-deleted products', function (): void {
        Queue::fake();
        productIds(501);
        Product::factory()->create()->delete();

        artisan('shop:recalc-prices', ['--all' => true])
            ->expectsOutput('Поставлено в очередь товаров: 501, задач: 2.')
            ->assertSuccessful();

        Queue::assertPushed(RecalculatePrices::class, 2);
        expect(app(RecalculationProgress::class)->current()['total'])->toBe(501);
    });

    it('--all --sync recalculates right away', function (): void {
        Queue::fake();
        productIds(2);
        PriceRule::factory()->discountPercent(10)->create();
        forceFinalPrice('1.00');

        artisan('shop:recalc-prices', ['--all' => true, '--sync' => true])
            ->expectsOutput('Пересчитано товаров: 2.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        expect(Product::query()->pluck('final_price')->unique()->all())->toBe(['900.00']);
    });

    it('--boundaries picks rules with a boundary inside the window only', function (): void {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $inside = Product::factory()->create(['brand' => 'Север']);
        $outside = Product::factory()->create(['brand' => 'Уют']);
        $other = Product::factory()->create(['brand' => 'Лето']);
        PriceRule::factory()->discountPercent(10)->forBrands(['Север'])->create(['ends_at' => now()->subMinutes(5)]);
        PriceRule::factory()->discountPercent(10)->forBrands(['Уют'])->create(['ends_at' => now()->subMinutes(30)]);
        PriceRule::factory()->discountPercent(10)->forBrands(['Лето'])->create(['starts_at' => now()->addMinutes(5)]);

        artisan('shop:recalc-prices', ['--boundaries' => true])->assertSuccessful();

        Queue::assertPushed(RecalculatePrices::class, 1);
        Queue::assertPushed(RecalculatePrices::class, fn (RecalculatePrices $job): bool => $job->productIds === [$inside->id]);
        expect(file_get_contents(RecalcPricesCommand::lastRunPath()))->toBe((string) now()->getTimestamp());
        unset($outside, $other);
    });

    it('--boundaries uses the stored last run as the window start', function (): void {
        Queue::fake();
        $this->travelTo(now()->startOfMinute());
        $product = Product::factory()->create();
        PriceRule::factory()->discountPercent(10)->create(['starts_at' => now()->subMinutes(5)]);
        file_put_contents(RecalcPricesCommand::lastRunPath(), (string) now()->subMinutes(2)->getTimestamp());
        // A deploy clears the cache; the last run must survive it.
        Cache::flush();

        artisan('shop:recalc-prices', ['--boundaries' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        expect($product->exists)->toBeTrue();
    });

    it('restores the base price after a promo ends (acceptance: within 15 minutes)', function (): void {
        $this->travelTo(now()->startOfMinute());
        $product = Product::factory()->create(['base_price' => '1000.00']);
        $rule = PriceRule::factory()->discountPercent(10)->create(['ends_at' => now()->addMinutes(10)]);
        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));
        expect($product->fresh()->final_price)->toBe('900.00');

        // Before the promo ends nothing changes; the cron run right after the end restores the price.
        $this->travelTo(now()->addMinutes(9));
        artisan('shop:recalc-prices', ['--boundaries' => true])->assertSuccessful();
        expect($product->fresh()->final_price)->toBe('900.00');

        $this->travelTo(now()->addMinutes(6));
        artisan('shop:recalc-prices', ['--boundaries' => true])->assertSuccessful();

        expect($product->fresh()->final_price)->toBe('1000.00');
    });
});

describe('schedule', function (): void {
    it('registers both recalculation entries without overlapping', function (): void {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $needle) => $events->first(fn ($event): bool => str_contains((string) $event->command, $needle));

        $boundaries = $find('shop:recalc-prices --boundaries');
        $all = $find('shop:recalc-prices --all');

        expect($boundaries)->not->toBeNull()
            ->and($boundaries->expression)->toBe('*/15 * * * *')
            ->and($boundaries->withoutOverlapping)->toBeTrue()
            ->and($all)->not->toBeNull()
            ->and($all->expression)->toBe('10 3 * * *')
            ->and($all->withoutOverlapping)->toBeTrue()
            ->and($boundaries->expiresAt)->toBe(10)
            ->and($all->expiresAt)->toBe(10)
            ->and($find('shop:heartbeat')->expiresAt)->toBe(10)
            ->and($find('shop:heartbeat'))->not->toBeNull();
    });
});

describe('admin banner', function (): void {
    it('shows the progress while a recalculation runs', function (): void {
        $progress = app(RecalculationProgress::class);
        $progress->add(10);
        $progress->advance(3);

        Livewire::test(PriceRecalcBanner::class)
            ->assertSee('Пересчёт цен: 3 из 10')
            ->assertSeeHtml('wire:poll');
    });

    it('renders nothing when idle', function (): void {
        Livewire::test(PriceRecalcBanner::class)
            ->assertDontSee('Пересчёт цен')
            ->assertDontSeeHtml('wire:poll');
    });
});
