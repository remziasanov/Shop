<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Enums\OrderStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SystemStatus;
use App\Filament\Widgets\LastImportWidget;
use App\Filament\Widgets\ProductsStatsWidget;
use App\Filament\Widgets\RequestsChartWidget;
use App\Filament\Widgets\RequestsStatsWidget;
use App\Models\Import;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Content\DefaultPageTemplates;
use App\Services\Dashboard\DashboardStats;
use App\Services\System\Heartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-27 12:00:00'); // fixed noon: the "today" and "7 days" counters must not depend on the wall clock
    Cache::flush();
    @unlink(app(Heartbeat::class)->path());
});

afterEach(function (): void {
    Carbon::setTestNow();
    @unlink(app(Heartbeat::class)->path());
});

function seedRequests(): void
{
    Order::factory()->order()->count(2)->create();
    Order::factory()->reservation()->create();
    Order::factory()->inquiry()->count(3)->create();
    Order::factory()->order()->create(['created_at' => now()->subDays(3)]);
    Order::factory()->inquiry()->create(['created_at' => now()->subDays(10)]);
    // Expires within 24 h (counted), later than 24 h, overdue, and a cancelled one: only the first counts.
    Order::factory()->reservation()->reservedUntil(now()->addHours(5))->create(['created_at' => now()->subDays(1)]);
    Order::factory()->reservation()->reservedUntil(now()->addHours(30))->create(['created_at' => now()->subDays(20)]);
    Order::factory()->reservation()->reservedUntil(now()->subHour())->create(['created_at' => now()->subDays(20)]);
    Order::factory()->reservation()->status(OrderStatus::Cancelled)->reservedUntil(now()->addHours(2))->create(['created_at' => now()->subDays(20)]);
}

it('counts requests of today and of the week by type and the expiring reservations', function (): void {
    seedRequests();

    $stats = app(DashboardStats::class);
    $requests = $stats->requests();

    expect($requests['today'])->toBe(['order' => 2, 'reservation' => 1, 'inquiry' => 3])
        ->and($requests['week'])->toBe(['order' => 3, 'reservation' => 2, 'inquiry' => 3])
        ->and($stats->expiringReservations())->toBe(1);
});

it('counts active products, products without photos and sold-out tracked products', function (): void {
    $withPhoto = Product::factory()->create(['stock_qty' => 5]);
    ProductImage::factory()->create(['product_id' => $withPhoto->id]);
    Product::factory()->create(['stock_qty' => 5]);                           // no photo
    Product::factory()->outOfStock()->create();                                // no photo, sold out
    Product::factory()->untracked()->create();                                 // no photo, untracked: not sold out
    Product::factory()->create(['stock_qty' => 3, 'reserved_qty' => 3]);       // fully reserved: sold out
    Product::factory()->inactive()->outOfStock()->create();                    // inactive: ignored

    expect(app(DashboardStats::class)->products())->toBe(['active' => 5, 'without_photo' => 4, 'out_of_stock' => 2]);
});

it('builds a 30 day series with zero-filled days', function (): void {
    Carbon::setTestNow('2026-10-27 12:00:00');
    Order::factory()->count(2)->create();
    Order::factory()->create(['created_at' => now()->subDays(5)]);
    Order::factory()->create(['created_at' => now()->subDays(29)]);
    Order::factory()->create(['created_at' => now()->subDays(30)]);

    $points = app(DashboardStats::class)->requestsByDay();

    expect($points)->toHaveCount(30)
        ->and($points[0])->toBe(['date' => '2026-09-28', 'count' => 1])
        ->and($points[29])->toBe(['date' => '2026-10-27', 'count' => 2])
        ->and($points[24])->toBe(['date' => '2026-10-22', 'count' => 1])
        ->and(array_sum(array_column($points, 'count')))->toBe(4)
        ->and($points[10]['count'])->toBe(0);
});

it('renders the dashboard for an administrator and a manager', function (string $role): void {
    seedRequests();
    Product::factory()->outOfStock()->create();
    actingAs(User::factory()->{$role}()->create());

    $this->get(Dashboard::getUrl())->assertOk()
        ->assertSee('wire:name="app.filament.widgets.requests-stats-widget"', false)
        ->assertSee('wire:name="app.filament.widgets.requests-chart-widget"', false)
        ->assertSee('wire:name="app.filament.widgets.last-import-widget"', false);
})->with(['admin', 'manager']);

it('shows the request numbers in the stats widget', function (): void {
    seedRequests();
    actingAs(User::factory()->admin()->create());

    Livewire::test(RequestsStatsWidget::class)
        ->assertSee('Обращения сегодня')
        ->assertSee('Заказы: 2 · Резервы: 1 · Заявки: 3')
        ->assertSee('Резервы истекают в ближайшие 24 часа');

    Livewire::test(ProductsStatsWidget::class)->assertSee('Активных товаров');
});

it('feeds the chart widget with 30 points', function (): void {
    actingAs(User::factory()->admin()->create());
    Order::factory()->count(3)->create();

    $method = new ReflectionMethod(RequestsChartWidget::class, 'getData');
    $data = $method->invoke(new RequestsChartWidget);

    expect($data['labels'])->toHaveCount(30)
        ->and($data['datasets'][0]['data'])->toHaveCount(30)
        ->and(end($data['datasets'][0]['data']))->toBe(3);

    Livewire::test(RequestsChartWidget::class)->assertSee('Обращения по дням');
});

it('shows the last import with its status and totals', function (): void {
    actingAs(User::factory()->admin()->create());

    Livewire::test(LastImportWidget::class)->assertSee('Импортов ещё не было');

    Import::factory()->status(ImportStatus::Completed)->create([
        'original_name' => 'old.csv', 'finished_at' => now()->subDays(2), 'rows_total' => 10,
    ]);
    $last = Import::factory()->status(ImportStatus::CompletedWithErrors)->create([
        'original_name' => 'fresh.csv', 'finished_at' => now()->subHour(), 'rows_total' => 120,
        'created_count' => 100, 'updated_count' => 15, 'error_count' => 5,
    ]);

    Livewire::test(LastImportWidget::class)
        ->assertSee('fresh.csv')
        ->assertDontSee('old.csv')
        ->assertSee('Завершён с ошибками')
        ->assertSee('Строк: 120 · создано: 100 · обновлено: 15 · ошибок: 5')
        ->assertSee(route('filament.admin.resources.imports.view', ['record' => $last], false));
});

it('shows the cron warning when the heartbeat is missing or stale and hides it when fresh', function (string $role): void {
    actingAs(User::factory()->{$role}()->create());
    $text = 'Планировщик не запускался более 5 минут — проверьте cron';

    $this->get(Dashboard::getUrl())->assertSee($text)->assertSee(route('admin.help.cron'));

    Carbon::setTestNow('2026-10-27 12:00:00');
    app(Heartbeat::class)->touch();
    Carbon::setTestNow('2026-10-27 12:09:00');
    $this->get(Dashboard::getUrl())->assertSee($text);

    app(Heartbeat::class)->touch();
    $this->get(Dashboard::getUrl())->assertDontSee($text);
})->with(['admin', 'manager']);

it('shows the cron warning on the system page too', function (): void {
    actingAs(User::factory()->admin()->create());

    $this->get(SystemStatus::getUrl())->assertSee('Планировщик не запускался более 5 минут');
});

it('renders the cron instructions with both lines and requires authentication', function (): void {
    $this->get(route('admin.help.cron'))->assertRedirect();

    actingAs(User::factory()->manager()->create());

    $this->get(route('admin.help.cron'))
        ->assertOk()
        ->assertSee('artisan schedule:run', false)
        ->assertSee('artisan queue:work --stop-when-empty --max-time=50 --tries=3', false)
        ->assertSee('/usr/local/php/cgi/8.3/bin/php', false)
        ->assertSee(base_path());
});

it('reminds administrators, but not managers, about template legal pages', function (): void {
    expect(Page::query()->where('slug', DefaultPageTemplates::PRIVACY_SLUG)->exists())->toBeTrue();
    $notice = 'Шаблон — проверить у юриста';

    actingAs(User::factory()->manager()->create());
    $this->get(Dashboard::getUrl())->assertDontSee($notice);

    actingAs(User::factory()->admin()->create());
    $this->get(Dashboard::getUrl())->assertSee($notice);

    Page::query()->whereIn('slug', [DefaultPageTemplates::PRIVACY_SLUG, DefaultPageTemplates::CONSENT_SLUG])
        ->update(['content' => '<p>Наш текст</p>']);
    $this->get(Dashboard::getUrl())->assertDontSee($notice);
});
