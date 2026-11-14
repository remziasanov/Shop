<?php

declare(strict_types=1);

use App\Enums\ExportType;
use App\Enums\OrderChannel;
use App\Filament\Widgets\RequestsByChannelWidget;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use App\Services\Export\ExportManager;
use App\Services\Import\ImportReader;
use App\Services\Notifications\Messages\NewRequestMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * TM-14: the channel on the dashboard, in the orders export and in the notification to the shop.
 */
beforeEach(function (): void {
    Cache::flush();
});

it('counts the requests of the last 30 days by channel with their sums (TM-14)', function (): void {
    Order::factory()->create(['channel' => OrderChannel::Telegram, 'total' => '1500.50', 'created_at' => now()->subDays(2)]);
    Order::factory()->create(['channel' => OrderChannel::Telegram, 'total' => '500.00', 'created_at' => now()->subDays(5)]);
    Order::factory()->create(['channel' => OrderChannel::Web, 'total' => '100.00', 'created_at' => now()->subDay()]);
    Order::factory()->create(['channel' => OrderChannel::Pwa, 'total' => null, 'created_at' => now()->subDay()]);
    Order::factory()->create(['channel' => OrderChannel::Telegram, 'total' => '9999.00', 'created_at' => now()->subDays(45)]);

    $stats = app(DashboardStats::class)->requestsByChannel();

    expect($stats['telegram'])->toBe(['count' => 2, 'sum' => 200050])
        ->and($stats['web'])->toBe(['count' => 1, 'sum' => 10000])
        ->and($stats['pwa'])->toBe(['count' => 1, 'sum' => 0]);
});

it('shows the channel widget to a manager with the order permission (TM-14)', function (): void {
    Order::factory()->create(['channel' => OrderChannel::Telegram, 'total' => '1500.00']);
    actingAs(User::factory()->admin()->create());

    Livewire::test(RequestsByChannelWidget::class)
        ->assertSee('Обращения по каналам за 30 дней')
        ->assertSee('Telegram')
        ->assertSee('Сайт')
        ->assertSee('Приложение');
});

it('adds the «Канал» column to the orders export (TM-14, OR-06)', function (): void {
    $order = Order::factory()->create(['channel' => OrderChannel::Telegram]);
    OrderItem::factory()->create(['order_id' => $order->id]);
    $web = Order::factory()->create(['channel' => OrderChannel::Web]);
    OrderItem::factory()->create(['order_id' => $web->id]);

    $export = app(ExportManager::class)->request(ExportType::Orders, [], User::factory()->admin()->create());
    $rows = array_values(iterator_to_array((new ImportReader)->open(Storage::disk('local')->path((string) $export->file_path), 'Обращения')));

    expect($rows[0][17])->toBe('Канал')
        ->and(collect($rows)->slice(1)->pluck(17)->sort()->values()->all())->toBe(['Telegram', 'Сайт']);
});

it('names the channel in the notification to the shop (TM-14)', function (): void {
    $order = Order::factory()->withItems(1)->create(['channel' => OrderChannel::Telegram]);

    expect((new NewRequestMessage)->render($order))->toContain('Канал: Telegram');
});
