<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\OrderStatus;
use App\Filament\Resources\Exports\ExportResource;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Jobs\ExportOrders;
use App\Models\Export;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Export\ExportManager;
use App\Services\Export\OrdersExporter;
use App\Services\Import\ImportReader;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @return array<int, list<string|null>>
 */
function ordersFile(Export $export): array
{
    return iterator_to_array((new ImportReader)->open(Storage::disk('local')->path((string) $export->file_path), 'Обращения'));
}

it('exports one row per item with the order data (EX-02)', function (): void {
    $order = Order::factory()->create([
        'number' => 'Z-00010', 'customer_name' => 'Иван Тестов', 'customer_phone' => '+79001234567', 'customer_email' => 'ivan@example.com',
        'delivery_method' => 'Курьер', 'delivery_address' => 'ул. Примерная, 1', 'payment_method' => 'Наличными', 'total' => '2500.00', 'customer_comment' => 'Быстрее',
    ]);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => 'A-1', 'name' => 'Кресло', 'price' => '1000.00', 'qty' => 2, 'total' => '2000.00']);
    OrderItem::factory()->create(['order_id' => $order->id, 'sku' => 'B-1', 'name' => 'Стол', 'price' => '500.00', 'qty' => 1, 'total' => '500.00']);
    Order::factory()->reservation()->withItems(1)->create();

    $export = app(ExportManager::class)->request(ExportType::Orders, ['tab' => 'order'], User::factory()->admin()->create());
    $rows = array_values(ordersFile($export));

    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->rows)->toBe(2)
        ->and($export->file_path)->toMatch('#^exports/\d+/orders-\d{8}-\d{6}\.xlsx$#')
        ->and($rows)->toHaveCount(3)
        ->and($rows[0][0])->toBe('Номер')
        ->and($rows[1][0])->toBe('Z-00010')
        ->and($rows[1][1])->toBe('Заказ')
        ->and($rows[1][2])->toBe('Новый')
        ->and($rows[1][4])->toBe('Иван Тестов')
        ->and($rows[1][5])->toBe('+79001234567')
        ->and($rows[1][6])->toBe('ivan@example.com')
        ->and($rows[1][7])->toBe('Курьер')
        ->and($rows[1][8])->toBe('ул. Примерная, 1')
        ->and($rows[1][9])->toBe('Наличными')
        ->and($rows[1][10])->toBe('A-1')
        ->and($rows[1][11])->toBe('Кресло')
        ->and((float) $rows[1][12])->toBe(1000.0)
        ->and((float) $rows[1][13])->toBe(2.0)
        ->and((float) $rows[1][14])->toBe(2000.0)
        ->and((float) $rows[1][15])->toBe(2500.0)
        ->and($rows[1][16])->toBe('Быстрее')
        ->and($rows[2][10])->toBe('B-1')
        ->and($rows[2][0])->toBe('Z-00010');
});

it('builds the set of orders from the parameters', function (): void {
    $a = Order::factory()->status(OrderStatus::Confirmed)->withItems(2)->create(['created_at' => '2026-03-10 10:00:00', 'customer_phone' => '+79001234567', 'delivery_method' => 'Курьер']);
    Order::factory()->withItems(1)->create(['created_at' => '2026-01-10 10:00:00']);
    Order::factory()->reservation()->withItems(1)->create();
    $exporter = app(OrdersExporter::class);

    expect($exporter->count([]))->toBe(4)
        ->and($exporter->count(['type' => 'order']))->toBe(3)
        ->and($exporter->count(['status' => 'confirmed']))->toBe(2)
        ->and($exporter->count(['created_from' => '2026-03-01', 'created_until' => '2026-03-31']))->toBe(2)
        ->and($exporter->count(['delivery_method' => 'Курьер']))->toBe(2)
        ->and($exporter->count(['search' => '8 (900) 123-45-67']))->toBe(2)
        ->and($exporter->count(['tab' => 'reservation', 'type' => 'order']))->toBe(0)
        ->and($exporter->query(['status' => 'confirmed'])->pluck('id')->all())->toBe([$a->id]);
});

it('exports by the current tab, filters and search of the list', function (): void {
    $user = User::factory()->manager()->create();
    actingAs($user);
    Order::factory()->status(OrderStatus::Confirmed)->withItems(2)->create(['customer_phone' => '+79001234567']);
    Order::factory()->status(OrderStatus::Confirmed)->withItems(1)->create(['customer_phone' => '+79117654321']);
    Order::factory()->withItems(1)->create();
    Order::factory()->reservation()->withItems(1)->create();

    Livewire::test(ListOrders::class)
        ->set('activeTab', 'order')
        ->filterTable('status', 'confirmed')
        ->searchTable('900 123')
        ->callAction('export_excel')
        ->assertNotified();

    $export = Export::query()->sole();

    expect($export->type)->toBe(ExportType::Orders)
        ->and($export->status)->toBe(ExportStatus::Completed)
        ->and($export->rows)->toBe(2)
        ->and($export->user_id)->toBe($user->id)
        ->and($export->params)->toBe(['tab' => 'order', 'status' => 'confirmed', 'search' => '900 123']);
});

it('queues a big export and shows it in the journal', function (): void {
    Queue::fake();
    config(['shop.export_sync_limit' => 2]);
    $user = User::factory()->admin()->create();
    actingAs($user);
    Order::factory()->count(3)->withItems(1)->create();

    Livewire::test(ListOrders::class)->callAction('export_excel')->assertNotified();

    $export = Export::query()->sole();
    Queue::assertPushed(ExportOrders::class, fn (ExportOrders $job): bool => $job->exportId === $export->id);

    expect($export->status)->toBe(ExportStatus::Queued)->and($export->type)->toBe(ExportType::Orders);

    // The job builds the file.
    (new ExportOrders($export->id))->handle(app(ExportManager::class));
    expect($export->fresh()->status)->toBe(ExportStatus::Completed)->and($export->fresh()->rows)->toBe(3);

    $this->get(ExportResource::getUrl('index'))->assertOk()->assertSee('Обращения');
});

it('tells when there is nothing to export', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(ListOrders::class)->callAction('export_excel')->assertNotified();

    expect(Export::query()->count())->toBe(0);
});
