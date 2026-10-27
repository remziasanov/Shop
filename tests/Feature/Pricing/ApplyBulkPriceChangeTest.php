<?php

declare(strict_types=1);

use App\Enums\BulkPriceOperation;
use App\Enums\PriceChangeSource;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\ApplyBulkPriceChange;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\BulkPriceChanger;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function bulkAction(): TestAction
{
    return TestAction::make('change_price')->table()->bulk();
}

describe('ApplyBulkPriceChange job', function (): void {
    it('applies the change once even when it runs twice', function (): void {
        $admin = User::factory()->admin()->create();
        $a = Product::factory()->create(['base_price' => '1000.00']);
        $b = Product::factory()->create(['base_price' => '500.00']);
        $targets = app(BulkPriceChanger::class)->computeTargets([$a->id, $b->id], BulkPriceOperation::IncreasePercent, '10');

        dispatch_sync(new ApplyBulkPriceChange($targets, $admin->id));
        dispatch_sync(new ApplyBulkPriceChange($targets, $admin->id));

        expect($a->fresh()->base_price)->toBe('1100.00')
            ->and($b->fresh()->base_price)->toBe('550.00')
            ->and(PriceHistory::query()->count())->toBe(2);
    });

    it('skips a product whose base price changed in the meantime', function (): void {
        $a = Product::factory()->create(['base_price' => '1000.00']);
        $b = Product::factory()->create(['base_price' => '500.00']);
        $targets = app(BulkPriceChanger::class)->computeTargets([$a->id, $b->id], BulkPriceOperation::IncreasePercent, '10');

        $a->update(['base_price' => '2000.00']);

        $result = app(BulkPriceChanger::class)->applyTargets($targets, null);

        expect($result)->toBe(['changed' => 1, 'skipped' => 1])
            ->and($a->fresh()->base_price)->toBe('2000.00')
            ->and($b->fresh()->base_price)->toBe('550.00');
    });

    it('notifies the acting user about products skipped because their price changed since the preview', function (): void {
        $admin = User::factory()->admin()->create();
        $a = Product::factory()->create(['base_price' => '1000.00']);
        $b = Product::factory()->create(['base_price' => '500.00']);
        $targets = app(BulkPriceChanger::class)->computeTargets([$a->id, $b->id], BulkPriceOperation::IncreasePercent, '10');

        // A second bulk change ran first.
        $a->update(['base_price' => '2000.00']);

        dispatch_sync(new ApplyBulkPriceChange($targets, $admin->id));

        $notification = $admin->notifications()->sole();
        expect($notification->data['title'])->toBe(__('admin_pricing.bulk.skipped', ['count' => 1]));
    });

    it('sends no notification when nothing was skipped', function (): void {
        $admin = User::factory()->admin()->create();
        $a = Product::factory()->create(['base_price' => '1000.00']);
        $targets = app(BulkPriceChanger::class)->computeTargets([$a->id], BulkPriceOperation::IncreasePercent, '10');

        dispatch_sync(new ApplyBulkPriceChange($targets, $admin->id));

        expect($admin->notifications()->count())->toBe(0);
    });

    it('records bulk history for the acting user although the queue has no authenticated user', function (): void {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['base_price' => '1000.00']);
        $targets = app(BulkPriceChanger::class)->computeTargets([$product->id], BulkPriceOperation::DecreaseFixed, '100');

        dispatch_sync(new ApplyBulkPriceChange($targets, $admin->id));

        $row = PriceHistory::query()->where('product_id', $product->id)->sole();

        expect($row->source)->toBe(PriceChangeSource::Bulk)
            ->and($row->user_id)->toBe($admin->id)
            ->and($product->fresh()->base_price)->toBe('900.00');
    });
});

describe('bulk price change action', function (): void {
    it('queues jobs of at most 200 products with the acting user', function (): void {
        $admin = User::factory()->admin()->create();
        actingAs($admin);
        Queue::fake();
        $ids = Product::factory()->count(BulkPriceChanger::JOB_SIZE + 1)->create(['base_price' => '100.00'])->pluck('id')->all();

        Livewire::test(ListProducts::class)
            ->selectTableRecords($ids)
            ->callAction(bulkAction(), ['operation' => 'increase_fixed', 'value' => '1'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin_pricing.bulk.queued', ['count' => BulkPriceChanger::JOB_SIZE + 1]));

        Queue::assertPushed(ApplyBulkPriceChange::class, 2);
        Queue::assertPushed(ApplyBulkPriceChange::class, fn (ApplyBulkPriceChange $job): bool => count($job->targets) <= BulkPriceChanger::JOB_SIZE && $job->userId === $admin->id);
        expect(Product::query()->where('base_price', '100.00')->count())->toBe(BulkPriceChanger::JOB_SIZE + 1);
    });

    it('refuses selections above the hard cap', function (): void {
        actingAs(User::factory()->admin()->create());
        Queue::fake();
        config(['shop.bulk_price_max_selection' => 2]);
        $ids = Product::factory()->count(3)->create(['base_price' => '100.00'])->pluck('id')->all();

        Livewire::test(ListProducts::class)
            ->selectTableRecords($ids)
            ->callAction(bulkAction(), ['operation' => 'increase_fixed', 'value' => '1'])
            ->assertNotified(__('admin_pricing.bulk.too_many', ['max' => 2]));

        Queue::assertNothingPushed();
        expect(Product::query()->where('base_price', '100.00')->count())->toBe(3);
    });

    it('cannot be executed by a manager: nothing is queued or changed', function (): void {
        actingAs(User::factory()->manager()->create());
        Queue::fake();
        $product = Product::factory()->create(['base_price' => '1000.00']);

        // The test helper refuses hidden actions, so drive the Livewire endpoints directly, as a forged request would.
        $component = Livewire::test(ListProducts::class)
            ->assertActionHidden(bulkAction())
            ->set('selectedTableRecords', [(string) $product->id])
            ->call('mountAction', 'change_price', [], ['table' => true, 'bulk' => true]);

        $component
            ->set('mountedActions.0.data', ['operation' => 'increase_percent', 'value' => '10'])
            ->call('callMountedAction');

        Queue::assertNothingPushed();
        expect($product->fresh()->base_price)->toBe('1000.00')
            ->and(PriceHistory::query()->count())->toBe(0);
    });

    it('is queued through the same raw Livewire calls for an administrator (control for the manager test)', function (): void {
        actingAs(User::factory()->admin()->create());
        Queue::fake();
        $product = Product::factory()->create(['base_price' => '1000.00']);

        Livewire::test(ListProducts::class)
            ->set('selectedTableRecords', [(string) $product->id])
            ->call('mountAction', 'change_price', [], ['table' => true, 'bulk' => true])
            ->set('mountedActions.0.data', ['operation' => 'increase_percent', 'value' => '10'])
            ->call('callMountedAction');

        Queue::assertPushed(ApplyBulkPriceChange::class, 1);
    });
});

it('does not report products already changed by an earlier attempt as skipped', function () {
    $product = Product::factory()->create(['base_price' => '100.00']);
    $changer = app(BulkPriceChanger::class);
    $targets = [$product->id => ['old' => 10000, 'new' => 11000]];

    expect($changer->applyTargets($targets, null))->toBe(['changed' => 1, 'skipped' => 0])
        ->and($changer->applyTargets($targets, null))->toBe(['changed' => 0, 'skipped' => 0])
        ->and($product->fresh()->base_price)->toBe('110.00');
});
