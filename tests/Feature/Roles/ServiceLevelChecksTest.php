<?php

declare(strict_types=1);

use App\Enums\BulkPriceOperation;
use App\Enums\ExportType;
use App\Enums\ImportMode;
use App\Enums\ImportStatus;
use App\Jobs\ApplyBulkPriceChange;
use App\Models\Import;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Export\ExportManager;
use App\Services\Home\HomeHtmlBlocks;
use App\Services\Import\ImportRunner;
use App\Services\Orders\OrderService;
use App\Services\Pricing\BulkPriceChanger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;

/**
 * RB-11: rights are enforced in the services, whatever UI (or none) calls them.
 */
describe('permission checks inside services', function (): void {
    it('BulkPriceChanger refuses an actor without prices.bulk and queues for one with it', function (): void {
        Queue::fake();
        $product = Product::factory()->create(['base_price' => '100.00']);
        $changer = app(BulkPriceChanger::class);

        expect(fn () => $changer->dispatch([$product->id], BulkPriceOperation::IncreasePercent, '10', User::factory()->manager()->create()))
            ->toThrow(AuthorizationException::class);
        Queue::assertNothingPushed();

        $result = $changer->dispatch([$product->id], BulkPriceOperation::IncreasePercent, '10', staffWith(['prices.bulk']));

        expect($result['products'])->toBe(1);
        Queue::assertPushed(ApplyBulkPriceChange::class, 1);
    });

    it('OrderService::delete needs orders.delete when a user acts', function (): void {
        $order = Order::factory()->create();
        $service = app(OrderService::class);

        expect(fn () => $service->delete($order, User::factory()->manager()->create()))->toThrow(AuthorizationException::class)
            ->and($order->refresh()->trashed())->toBeFalse();

        $service->delete($order, staffWith(['orders.delete']));

        expect($order->refresh()->trashed())->toBeTrue();
    });

    it('ExportManager refuses a user without the export right of the type', function (): void {
        $manager = app(ExportManager::class);
        $noRights = staffWith(['products.view']);

        expect(fn () => $manager->request(ExportType::Catalog, [], $noRights))->toThrow(AuthorizationException::class)
            ->and(fn () => $manager->request(ExportType::Orders, [], $noRights))->toThrow(AuthorizationException::class);
    });

    it('ImportRunner refuses the full sync mode without imports.full_sync', function (): void {
        $import = Import::factory()->status(ImportStatus::Analyzed)->mode(ImportMode::FullSync)->create();
        $runner = app(ImportRunner::class);

        expect(fn () => $runner->start($import, User::factory()->manager()->create()))->toThrow(AuthorizationException::class)
            ->and($import->refresh()->status)->toBe(ImportStatus::Analyzed);

        Queue::fake();
        $runner->start($import, staffWith(['imports.run', 'imports.full_sync']));

        expect($import->refresh()->status)->toBe(ImportStatus::Queued);
    });

    it('keeps the HTML blocks of the home page away from users without html_block.edit', function (): void {
        $html = app(HomeHtmlBlocks::class);
        $stored = [['type' => 'text', 'data' => []], ['type' => 'html', 'data' => ['code' => '<b>x</b>']]];
        $submitted = [['type' => 'text', 'data' => []], ['type' => 'html', 'data' => ['code' => '<script>']]];

        $manager = User::factory()->manager()->create();

        expect($html->visibleTo($manager, $stored))->toBe([['type' => 'text', 'data' => []]])
            ->and($html->prepareForSave($manager, $submitted, $stored))->toBe($stored)
            ->and($html->prepareForSave(staffWith(['html_block.edit']), $submitted, $stored))->toBe($submitted);
    });
});
