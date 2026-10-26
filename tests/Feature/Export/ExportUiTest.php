<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Resources\Exports\Pages\ListExports;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\ExportCatalog;
use App\Models\Category;
use App\Models\Export;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
});

it('lets an administrator and a manager open the export page', function (string $role): void {
    actingAs(User::factory()->{$role}()->create());

    $this->get(ExportResource::getUrl('index'))->assertOk();
})->with(['admin', 'manager']);

it('starts a catalog export from the form with category and activity filters', function (): void {
    $user = User::factory()->manager()->create();
    actingAs($user);
    $root = Category::factory()->create();
    $child = Category::factory()->childOf($root)->create();
    Product::factory()->create(['category_id' => $child->id]);
    Product::factory()->create(['category_id' => $child->id, 'is_active' => false]);
    Product::factory()->create();

    Livewire::test(ListExports::class)
        ->callAction('new_export', ['category_id' => $root->id, 'active' => 'active'])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $export = Export::query()->sole();

    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->rows)->toBe(1)
        ->and($export->user_id)->toBe($user->id)
        ->and($export->params)->toEqual(['category_id' => $root->id, 'active' => 'active']);
});

it('shows managers only their own exports and administrators all of them', function (): void {
    $manager = User::factory()->manager()->create();
    $mine = Export::factory()->create(['user_id' => $manager->id]);
    $foreign = Export::factory()->create(['user_id' => User::factory()->manager()->create()->id]);

    actingAs($manager);
    Livewire::test(ListExports::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$foreign]);

    actingAs(User::factory()->admin()->create());
    Livewire::test(ListExports::class)->assertCanSeeTableRecords([$mine, $foreign]);
});

it('offers the download action only for finished exports', function (): void {
    $user = User::factory()->admin()->create();
    actingAs($user);
    $done = Export::factory()->status(ExportStatus::Completed)->create(['user_id' => $user->id, 'file_path' => 'exports/1/a.xlsx']);
    $queued = Export::factory()->create(['user_id' => $user->id]);

    Livewire::test(ListExports::class)
        ->assertTableActionVisible('download', $done)
        ->assertTableActionHidden('download', $queued);
});

describe('bulk export of selected products', function (): void {
    it('exports exactly the selected products', function (): void {
        $user = User::factory()->manager()->create();
        actingAs($user);
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        Product::factory()->create();

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(TestAction::make('export_excel')->table()->bulk())
            ->assertNotified();

        $export = Export::query()->sole();

        expect($export->status)->toBe(ExportStatus::Completed)
            ->and($export->rows)->toBe(2)
            ->and($export->params)->toBe(['product_ids' => [$a->id, $b->id]]);
        Storage::disk('local')->assertExists($export->file_path);
    });

    it('queues the export above the synchronous limit', function (): void {
        Queue::fake([ExportCatalog::class]);
        config(['shop.export_sync_limit' => 1]);
        actingAs(User::factory()->admin()->create());
        $ids = Product::factory()->count(2)->create()->modelKeys();

        Livewire::test(ListProducts::class)
            ->selectTableRecords($ids)
            ->callAction(TestAction::make('export_excel')->table()->bulk());

        expect(Export::query()->sole()->status)->toBe(ExportStatus::Queued);
        Queue::assertPushed(ExportCatalog::class);
    });
});
