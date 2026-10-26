<?php

declare(strict_types=1);

use App\Enums\ProductFlag;
use App\Filament\Pages\Settings\ManagePricingSettings;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\RecalculatePrices;
use App\Models\Category;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CategoryTree;
use App\Services\Pricing\RecalculationProgress;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Paths that write product inputs of price rules without the product observer must still reprice (PR-02, D-030).
 * The queue is "sync" in tests, so queued recalculation runs inline.
 */

describe('bulk actions on the product list', function (): void {
    it('reprices products moved to a category with a rule', function (): void {
        actingAs(User::factory()->admin()->create());
        $target = Category::factory()->create();
        PriceRule::factory()->forCategories([$target])->markupPercent(10)->create();
        $moved = Product::factory()->create(['base_price' => '1000.00']);
        $staying = Product::factory()->create(['base_price' => '1000.00']);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$moved->id])
            ->callAction(TestAction::make('move')->table()->bulk(), ['category_id' => $target->id]);

        expect($moved->fresh()->final_price)->toBe('1100.00')
            ->and($staying->fresh()->final_price)->toBe('1000.00');
    });

    it('applies a flags rule after the flag was set in bulk and drops it after unsetting', function (): void {
        actingAs(User::factory()->admin()->create());
        PriceRule::factory()->forFlags([ProductFlag::Featured])->discountPercent(10)->create();
        $product = Product::factory()->create(['base_price' => '1000.00', 'is_featured' => false]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(TestAction::make('set_flags')->table()->bulk(), ['featured' => 'on', 'new' => 'keep']);

        expect($product->fresh()->final_price)->toBe('900.00');

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(TestAction::make('set_flags')->table()->bulk(), ['featured' => 'off', 'new' => 'keep']);

        expect($product->fresh()->final_price)->toBe('1000.00');
    });

    it('does not queue anything for actions that do not affect prices', function (): void {
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        Queue::fake();

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(TestAction::make('deactivate')->table()->bulk());

        Queue::assertNothingPushed();
    });
});

describe('category tree changes', function (): void {
    it('reprices the products of a moved subtree under a parent with a rule', function (): void {
        $root = Category::factory()->create();
        PriceRule::factory()->forCategories([$root])->markupPercent(10)->create();
        $branch = Category::factory()->create();
        $leaf = Category::factory()->childOf($branch)->create();
        $inBranch = Product::factory()->inCategory($branch)->create(['base_price' => '1000.00']);
        $inLeaf = Product::factory()->inCategory($leaf)->create(['base_price' => '1000.00']);
        $elsewhere = Product::factory()->inCategory(Category::factory()->create())->create(['base_price' => '1000.00']);

        $branch->update(['parent_id' => $root->id]);

        expect($inBranch->fresh()->final_price)->toBe('1100.00')
            ->and($inLeaf->fresh()->final_price)->toBe('1100.00')
            ->and($elsewhere->fresh()->final_price)->toBe('1000.00');

        // And back to the top level: the rule of the former parent no longer applies.
        $branch->refresh()->update(['parent_id' => null]);

        expect($inBranch->fresh()->final_price)->toBe('1000.00')
            ->and($inLeaf->fresh()->final_price)->toBe('1000.00');
    });

    it('reprices products moved to the parent when a category is deleted', function (): void {
        $root = Category::factory()->create();
        PriceRule::factory()->forCategories([$root], includeSubcategories: false)->markupPercent(10)->create();
        $middle = Category::factory()->childOf($root)->create();
        $product = Product::factory()->inCategory($middle)->create(['base_price' => '1000.00']);

        app(CategoryTree::class)->deleteMovingToParent($middle);

        expect($product->fresh()->category_id)->toBe($root->id)
            ->and($product->fresh()->final_price)->toBe('1100.00');
    });

    it('reprices the subtree of children lifted by a deleted category', function (): void {
        $root = Category::factory()->create();
        PriceRule::factory()->forCategories([$root])->markupPercent(10)->create();
        $other = Category::factory()->create();
        $middle = Category::factory()->childOf($other)->create();
        $child = Category::factory()->childOf($middle)->create();
        $product = Product::factory()->inCategory($child)->create(['base_price' => '1000.00']);

        // Deleting "middle" lifts "child" under "other": no rule there, nothing changes.
        app(CategoryTree::class)->deleteMovingToParent($middle);

        expect($product->fresh()->final_price)->toBe('1000.00')
            ->and($child->fresh()->parent_id)->toBe($other->id);
    });
});

describe('pricing settings', function (): void {
    it('queues a recalculation of all products when the rounding changes', function (): void {
        actingAs(User::factory()->admin()->create());
        Product::factory()->count(3)->create();
        Product::factory()->create()->delete();
        Queue::fake();

        Livewire::test(ManagePricingSettings::class)
            ->fillForm(['rounding_step' => '50'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin_pricing.settings.recalculating'));

        Queue::assertPushed(RecalculatePrices::class, 1);
        Queue::assertPushed(RecalculatePrices::class, fn (RecalculatePrices $job): bool => count($job->productIds) === 3);
    });

    it('reprices products with the new settings', function (): void {
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create(['base_price' => '1001.00']);

        Livewire::test(ManagePricingSettings::class)
            ->fillForm(['rounding_step' => '10', 'rounding_direction' => 'up', 'rounding_scope' => 'all'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->fresh()->final_price)->toBe('1010.00');
    });

    it('does not recalculate when only storefront display toggles change', function (): void {
        actingAs(User::factory()->admin()->create());
        Product::factory()->create();
        Queue::fake();

        Livewire::test(ManagePricingSettings::class)
            ->fillForm(['show_old_price' => false, 'show_discount_percent' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNothingPushed();
    });
});

it('reprices a product restored from the trash', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);
    $product->delete();
    PriceRule::factory()->markupPercent(10)->create();

    expect(Product::withTrashed()->find($product->id)->final_price)->toBe('1000.00');

    $product->restore();

    expect($product->fresh()->final_price)->toBe('1100.00');
});

describe('recalculation progress', function (): void {
    it('survives a lock timeout without failing', function (): void {
        $lock = Mockery::mock();
        $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')->twice()->andReturn($lock);
        Log::shouldReceive('warning')->twice();

        $progress = app(RecalculationProgress::class);
        $progress->add(5);
        $progress->advance(5);
    });
});
