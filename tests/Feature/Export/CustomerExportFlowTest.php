<?php

declare(strict_types=1);

use App\Enums\CustomerNotificationType;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Jobs\GenerateCustomerOrdersExport;
use App\Mail\CustomerExportReadyMail;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\Export;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Customers\CustomerAnonymizer;
use App\Services\Export\CustomerExportService;
use App\Services\Export\CustomerOrdersExporter;
use App\Settings\CustomerSettings;
use App\Support\FileSize;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CX-01, CX-04…CX-09 (TZ 18.9): the export of the order history from the cabinet: synchronous and queued branches
 * with their boundaries, size and rate limits, the 24 hour period, the 410 after expiry, the owner check.
 */
beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    Cache::flush();
    RateLimiter::clear('customer-export-request:1');

    $this->customer = makeCustomer();
    signInCustomer($this, $this->customer);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cxExportBody(array $overrides = []): array
{
    return $overrides + [
        'from' => now()->subMonth()->toDateString(),
        'until' => now()->toDateString(),
        'format' => 'pdf',
    ];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function cxMakeOrders(Customer $customer, int $count, array $attributes = [], int $items = 0): void
{
    $orders = Order::factory()->count($count)->create($attributes + ['customer_id' => $customer->id, 'total' => '100.00', 'items_total' => '100.00', 'created_at' => now()->subDays(3)]);

    if ($items > 0) {
        foreach ($orders as $order) {
            OrderItem::factory()->count($items)->create(['order_id' => $order->id]);
        }
    }
}

function cxExportParams(array $overrides = []): array
{
    return $overrides + ['from' => now()->subMonth()->toDateString(), 'until' => now()->toDateString(), 'types' => [], 'statuses' => [], 'with_items' => false];
}

// ------------------------------------------------------------------ sync / queued boundaries (CX-04)

it('decides between the request and the queue at the thresholds', function (ExportType $type, bool $withItems, int $orders, bool $sync): void {
    cxMakeOrders($this->customer, $orders);

    $plan = app(CustomerExportService::class)->plan($this->customer, $type, cxExportParams(['with_items' => $withItems]));

    expect($plan->isAllowed())->toBeTrue()
        ->and($plan->orders)->toBe($orders)
        ->and($plan->sync)->toBe($sync);
})->with([
    'excel 300' => [ExportType::CustomerOrdersXlsx, false, 300, true],
    'excel 301' => [ExportType::CustomerOrdersXlsx, false, 301, false],
    'excel with items 300' => [ExportType::CustomerOrdersXlsx, true, 300, true],
    'pdf 200' => [ExportType::CustomerOrdersPdf, false, 200, true],
    'pdf 201' => [ExportType::CustomerOrdersPdf, false, 201, false],
    'pdf with items 50' => [ExportType::CustomerOrdersPdf, true, 50, true],
    'pdf with items 51' => [ExportType::CustomerOrdersPdf, true, 51, false],
]);

it('has the defaults of the TZ for thresholds, limits and retention (CX-04, CX-05, CX-07, CX-09)', function (): void {
    $s = app(CustomerSettings::class);

    expect([$s->export_sync_xlsx_max, $s->export_sync_pdf_max, $s->export_sync_pdf_items_max])->toBe([300, 200, 50])
        // The PDF limits are lowered from the TZ 1000 / 300 after the memory measurement (128 MB, section 2 wins).
        ->and([$s->export_max_pdf, $s->export_max_pdf_items, $s->export_max_pdf_rows, $s->export_max_xlsx_rows])->toBe([400, 100, 450, 20000])
        ->and([$s->export_retention_hours, $s->export_requests_per_hour, $s->export_max_pending, $s->export_downloads_per_hour])->toBe([24, 10, 3, 30])
        ->and($s->export_email_link)->toBeFalse();
});

it('answers a small Excel export at once as an attachment', function (): void {
    cxMakeOrders($this->customer, 3);

    $response = $this->post(route('customer.exports.store'), cxExportBody(['format' => 'xlsx']));

    $response->assertOk()->assertHeader('Content-Disposition');
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Type'))->toContain('spreadsheetml')
        ->and(Export::query()->count())->toBe(0);
});

it('answers a PDF at the synchronous limit of 200 requests at once (CX-04)', function (): void {
    cxMakeOrders($this->customer, 200);

    $response = $this->post(route('customer.exports.store'), cxExportBody());

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and(Export::query()->count())->toBe(0);
});

it('queues a PDF over the synchronous limit and shows the link in the cabinet (CX-04)', function (): void {
    Queue::fake();
    cxMakeOrders($this->customer, 201);

    $this->post(route('customer.exports.store'), cxExportBody())->assertRedirect(route('customer.exports'));

    $export = Export::query()->sole();
    expect($export->status)->toBe(ExportStatus::Queued)
        ->and($export->customer_id)->toBe($this->customer->id)
        ->and($export->user_id)->toBeNull()
        ->and($export->type)->toBe(ExportType::CustomerOrdersPdf)
        ->and(Str::isUuid($export->uuid))->toBeTrue();
    Queue::assertPushed(GenerateCustomerOrdersExport::class, fn (GenerateCustomerOrdersExport $job): bool => $job->exportId === $export->id);

    $this->get(route('customer.exports'))->assertOk()->assertSee('В очереди');
});

it('queues a PDF with items over 50 requests', function (): void {
    Queue::fake();
    cxMakeOrders($this->customer, 51);

    $this->post(route('customer.exports.store'), cxExportBody(['format' => 'pdf', 'with_items' => '1']))->assertRedirect(route('customer.exports'));

    expect(Export::query()->sole()->params['with_items'])->toBeTrue();
    Queue::assertPushed(GenerateCustomerOrdersExport::class, 1);
});

it('queues an Excel over 300 requests', function (): void {
    Queue::fake();
    cxMakeOrders($this->customer, 301);

    $this->post(route('customer.exports.store'), cxExportBody(['format' => 'xlsx']))->assertRedirect(route('customer.exports'));

    expect(Export::query()->sole()->type)->toBe(ExportType::CustomerOrdersXlsx);
    Queue::assertPushed(GenerateCustomerOrdersExport::class, 1);
});

it('sets the download period to 24 hours after the creation (CX-07)', function (): void {
    Queue::fake();
    $this->travelTo(now()->startOfSecond());
    cxMakeOrders($this->customer, 201);

    $this->post(route('customer.exports.store'), cxExportBody());

    expect(Export::query()->sole()->expires_at->equalTo(now()->addHours(24)))->toBeTrue();
});

// ------------------------------------------------------------------ size limits (CX-05)

it('refuses a PDF over the hard limits and suggests narrowing the period or Excel', function (bool $withItems, int $limit): void {
    Queue::fake();
    cxMakeOrders($this->customer, $limit + 1);

    $this->post(route('customer.exports.store'), cxExportBody(['with_items' => $withItems ? '1' : '0']))
        ->assertRedirect(route('customer.orders').'#export')
        ->assertSessionHasErrors('export');

    expect(session('errors')->first('export'))->toContain('Сузьте период')
        ->and(Export::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'without items 401' => [false, 400],
    'with items 101' => [true, 100],
]);

it('accepts a PDF exactly at the hard limit', function (): void {
    cxMakeOrders($this->customer, 400);

    $plan = app(CustomerExportService::class)->plan($this->customer, ExportType::CustomerOrdersPdf, cxExportParams());

    expect($plan->isAllowed())->toBeTrue()->and($plan->sync)->toBeFalse();
});

it('limits a PDF by table rows so that orders with many items cannot exhaust the memory', function (): void {
    // 30 orders x 15 items = 450 rows + 30 = 480 > 450, although 30 is far below the order limits.
    cxMakeOrders($this->customer, 30, items: 15);
    $service = app(CustomerExportService::class);

    expect($service->plan($this->customer, ExportType::CustomerOrdersPdf, cxExportParams(['with_items' => true]))->isAllowed())->toBeFalse()
        ->and($service->plan($this->customer, ExportType::CustomerOrdersPdf, cxExportParams(['with_items' => false]))->isAllowed())->toBeTrue()
        ->and($service->plan($this->customer, ExportType::CustomerOrdersXlsx, cxExportParams(['with_items' => true]))->isAllowed())->toBeTrue();
});

it('queues a PDF that is under the order threshold but has many rows', function (): void {
    // 40 orders x 8 items = 360 rows > 300 rows of the synchronous PDF.
    cxMakeOrders($this->customer, 40, items: 8);

    $plan = app(CustomerExportService::class)->plan($this->customer, ExportType::CustomerOrdersPdf, cxExportParams(['with_items' => true]));

    expect($plan->isAllowed())->toBeTrue()->and($plan->sync)->toBeFalse();
});

it('closes an export that was already processing when the job started again (a died run)', function (): void {
    $export = Export::factory()->forCustomer($this->customer)->status(ExportStatus::Processing)->create();

    app(CustomerExportService::class)->generate($export);

    expect($export->refresh()->status)->toBe(ExportStatus::Failed);
});

it('refuses an Excel over the row limit counting the items (CX-05)', function (): void {
    setSettings(CustomerSettings::class, ['export_max_xlsx_rows' => 10]);
    cxMakeOrders($this->customer, 4, items: 2);

    $service = app(CustomerExportService::class);

    // 4 requests + 8 items = 12 rows.
    expect($service->plan($this->customer, ExportType::CustomerOrdersXlsx, cxExportParams(['with_items' => true]))->isAllowed())->toBeFalse()
        ->and($service->plan($this->customer, ExportType::CustomerOrdersXlsx, cxExportParams(['with_items' => false]))->isAllowed())->toBeTrue();
});

it('says so when nothing matches', function (): void {
    $this->post(route('customer.exports.store'), cxExportBody())->assertSessionHasErrors('export');
});

it('validates the form (CX-01)', function (array $body, string $field): void {
    $this->post(route('customer.exports.store'), $body)->assertSessionHasErrors($field);
})->with([
    'no dates' => [['format' => 'pdf'], 'from'],
    'until before from' => [['from' => '2026-05-10', 'until' => '2026-05-01', 'format' => 'pdf'], 'until'],
    'bad format' => [['from' => '2026-05-01', 'until' => '2026-05-10', 'format' => 'csv'], 'format'],
    'bad type' => [['from' => '2026-05-01', 'until' => '2026-05-10', 'format' => 'pdf', 'types' => ['nope']], 'types.0'],
    'bad status' => [['from' => '2026-05-01', 'until' => '2026-05-10', 'format' => 'pdf', 'statuses' => ['nope']], 'statuses.0'],
]);

// ------------------------------------------------------------------ rate limits (CX-09)

it('limits export requests per hour with 429 and Retry-After', function (): void {
    setSettings(CustomerSettings::class, ['export_requests_per_hour' => 2]);
    cxMakeOrders($this->customer, 2);

    $this->post(route('customer.exports.store'), cxExportBody(['format' => 'xlsx']))->assertOk();
    $this->post(route('customer.exports.store'), cxExportBody(['format' => 'xlsx']))->assertOk();
    $this->post(route('customer.exports.store'), cxExportBody(['format' => 'xlsx']))->assertStatus(429)->assertHeader('Retry-After');
});

it('does not count refused requests against the limit', function (): void {
    setSettings(CustomerSettings::class, ['export_requests_per_hour' => 1]);

    $this->post(route('customer.exports.store'), cxExportBody())->assertSessionHasErrors('export');
    $this->post(route('customer.exports.store'), cxExportBody())->assertSessionHasErrors('export');
});

it('allows at most 3 unfinished exports at once', function (): void {
    Queue::fake();
    Export::factory()->count(3)->forCustomer($this->customer)->create();
    cxMakeOrders($this->customer, 201);

    $this->post(route('customer.exports.store'), cxExportBody())->assertStatus(429);

    Export::query()->first()?->update(['status' => ExportStatus::Completed]);

    $this->post(route('customer.exports.store'), cxExportBody())->assertRedirect(route('customer.exports'));
});

it('limits downloads per hour with 429', function (): void {
    setSettings(CustomerSettings::class, ['export_downloads_per_hour' => 2]);
    Storage::disk('local')->put('exports/customers/x/a.pdf', '%PDF-1.4 test');
    $export = Export::factory()->forCustomer($this->customer)->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/x/a.pdf']);

    $this->get(route('customer.exports.download', $export->uuid))->assertOk();
    $this->get(route('customer.exports.download', $export->uuid))->assertOk();
    $this->get(route('customer.exports.download', $export->uuid))->assertStatus(429);
});

// ------------------------------------------------------------------ download: owner, expiry (CX-07)

it('downloads a ready file of its owner as an attachment', function (): void {
    Storage::disk('local')->put('exports/customers/x/a.pdf', '%PDF-1.4 test');
    $export = Export::factory()->forCustomer($this->customer)->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/x/a.pdf']);

    $response = $this->get(route('customer.exports.download', $export->uuid));

    $response->assertOk();
    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->streamedContent())->toBe('%PDF-1.4 test');
});

it('answers 410 with the advice after the 24 hours', function (): void {
    Storage::disk('local')->put('exports/customers/x/a.pdf', '%PDF-1.4 test');
    $export = Export::factory()->forCustomer($this->customer)->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/x/a.pdf']);

    $this->travel(24)->hours();
    $this->travel(1)->minutes();

    $this->get(route('customer.exports.download', $export->uuid))->assertStatus(410)->assertSee('Файл удалён, сформируйте новый');
});

it('answers 410 when the file is gone from the disk', function (): void {
    $export = Export::factory()->forCustomer($this->customer)->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/x/missing.pdf']);

    $this->get(route('customer.exports.download', $export->uuid))->assertStatus(410);
});

it('answers 404 for a foreign or unknown export and never reveals it (IDOR)', function (): void {
    $other = makeCustomer();
    Storage::disk('local')->put('exports/customers/y/b.pdf', 'secret');
    $foreign = Export::factory()->forCustomer($other)->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/y/b.pdf']);

    $this->get(route('customer.exports.download', $foreign->uuid))->assertNotFound();
    $this->get(route('customer.exports.download', (string) Str::uuid()))->assertNotFound();
    $this->get(route('customer.exports.download', 'not-a-uuid'))->assertNotFound();
});

it('lists only own exports, the last 20 (CX-08)', function (): void {
    $other = makeCustomer();
    Export::factory()->count(22)->forCustomer($this->customer, ExportType::CustomerOrdersXlsx, ['from' => '2025-02-03'])->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/x/a.xlsx']);
    Export::factory()->forCustomer($other, ExportType::CustomerOrdersXlsx, ['from' => '2019-07-08'])->create();

    $response = $this->get(route('customer.exports'))->assertOk()->assertDontSee('08.07.2019');

    expect(substr_count($response->getContent(), 'data-export>'))->toBe(20);
});

it('shows «Файл удалён» instead of the button for an expired export', function (): void {
    Export::factory()->forCustomer($this->customer)->status(ExportStatus::Completed)->create(['file_path' => 'exports/customers/x/a.pdf', 'expires_at' => now()->subHour()]);

    $this->get(route('customer.exports'))->assertOk()->assertSee('Файл удалён')->assertDontSee('data-export-download', false);
});

it('closes the pages to guests (CX-01)', function (): void {
    session()->flush();
    nextRequest();
    $export = Export::factory()->forCustomer($this->customer)->create();

    $this->get(route('customer.exports'))->assertRedirect(route('customer.login'));
    $this->get(route('customer.exports.download', $export->uuid))->assertRedirect(route('customer.login'));
    $this->post(route('customer.exports.store'), cxExportBody())->assertRedirect(route('customer.login'));
});

// ------------------------------------------------------------------ history page (CX-01)

it('shows the «Выгрузить» block on the order history page', function (): void {
    $this->get(route('customer.orders'))->assertOk()
        ->assertSee('Выгрузить историю заказов')
        ->assertSee('С составом заказов')
        ->assertSee('Квартал')
        ->assertSee('name="format"', false);
});

it('exports only the own requests inside the period and with the chosen types and statuses (CX-01)', function (): void {
    $other = makeCustomer();
    cxMakeOrders($this->customer, 2);
    cxMakeOrders($this->customer, 1, ['number' => 'Z-OLD', 'created_at' => now()->subYear()]);
    cxMakeOrders($this->customer, 1, ['number' => 'R-RES', 'type' => 'reservation', 'status' => 'reserved']);
    cxMakeOrders($other, 1, ['number' => 'Z-FOREIGN']);
    Order::factory()->create(['number' => 'Z-GUEST', 'customer_id' => null, 'created_at' => now()->subDay()]);

    $exporter = app(CustomerOrdersExporter::class);

    expect($exporter->countOrders($this->customer, cxExportParams()))->toBe(3)
        ->and($exporter->countOrders($this->customer, cxExportParams(['types' => ['reservation']])))->toBe(1)
        ->and($exporter->countOrders($this->customer, cxExportParams(['statuses' => ['reserved']])))->toBe(1)
        ->and($exporter->countOrders($this->customer, cxExportParams(['from' => now()->subYears(2)->toDateString()])))->toBe(4)
        ->and($exporter->query($this->customer, cxExportParams())->pluck('number')->all())->not->toContain('Z-FOREIGN', 'Z-GUEST', 'Z-OLD');
});

it('streams the PDF data in chunks of 100 orders', function (): void {
    cxMakeOrders($this->customer, 250);

    $sizes = [];

    foreach (app(CustomerOrdersExporter::class)->chunks($this->customer, cxExportParams(), false) as $chunk) {
        $sizes[] = count($chunk);
    }

    expect($sizes)->toBe([100, 100, 50]);
});

// ------------------------------------------------------------------ background job (CX-06)

it('builds the file in the job, notifies the customer and sets the 24 hours from the readiness (CX-06)', function (): void {
    Mail::fake();
    cxMakeOrders($this->customer, 3, items: 2);
    $export = app(CustomerExportService::class)->queue($this->customer, ExportType::CustomerOrdersXlsx, cxExportParams(['with_items' => true]));

    // The sync queue of the tests has already run the job.
    $export->refresh();

    expect($export->status)->toBe(ExportStatus::Completed)
        ->and($export->file_path)->toBe('exports/customers/'.$this->customer->uuid.'/'.$export->uuid.'.xlsx')
        ->and($export->rows)->toBe(9)
        ->and($export->file_size)->toBeGreaterThan(100)
        ->and($export->expires_at->isFuture())->toBeTrue()
        ->and(Storage::disk('local')->exists($export->file_path))->toBeTrue();

    $notification = CustomerNotification::query()->where('customer_id', $this->customer->id)->sole();
    expect($notification->type)->toBe(CustomerNotificationType::ExportReady->value)
        ->and($notification->url)->toBe('/account/exports');
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('builds a PDF in the job on the private disk', function (): void {
    cxMakeOrders($this->customer, 2, items: 1);
    $export = app(CustomerExportService::class)->queue($this->customer, ExportType::CustomerOrdersPdf, cxExportParams(['with_items' => true]));
    $export->refresh();

    expect($export->status)->toBe(ExportStatus::Completed)
        ->and(Storage::disk('local')->get($export->file_path))->toStartWith('%PDF');
});

it('sends the e-mail with the link when the setting is on', function (): void {
    Mail::fake();
    setSettings(CustomerSettings::class, ['export_email_link' => true]);
    cxMakeOrders($this->customer, 1);

    $export = app(CustomerExportService::class)->queue($this->customer, ExportType::CustomerOrdersXlsx, cxExportParams());

    Mail::assertQueued(CustomerExportReadyMail::class, fn (CustomerExportReadyMail $mail): bool => $mail->hasTo($this->customer->email)
        && $mail->url === route('customer.exports.download', $export->refresh()->uuid));
});

it('marks a failed export with a readable message and a cabinet notice', function (): void {
    $export = Export::factory()->forCustomer($this->customer, ExportType::CustomerOrdersXlsx, [])->create(['params' => ['from' => '2026-01-01']]);

    app(CustomerExportService::class)->generate($export);

    $export->refresh();
    expect($export->status)->toBe(ExportStatus::Failed)
        ->and($export->failure_message)->toBe('Не удалось сформировать файл. Попробуйте ещё раз или сузьте период.')
        ->and(CustomerNotification::query()->where('customer_id', $this->customer->id)->value('title'))->toBe('Выгрузка не сформирована');

    $this->get(route('customer.exports'))->assertSee('Не удалось сформировать файл');
});

it('runs at most one PDF job per customer at a time (TZ 18.8)', function (): void {
    $pdf = Export::factory()->forCustomer($this->customer, ExportType::CustomerOrdersPdf)->create();
    $xlsx = Export::factory()->forCustomer($this->customer, ExportType::CustomerOrdersXlsx)->create();

    $pdfMiddleware = (new GenerateCustomerOrdersExport($pdf->id))->middleware();
    $xlsxMiddleware = (new GenerateCustomerOrdersExport($xlsx->id))->middleware();

    expect($pdfMiddleware)->toHaveCount(1)->and($pdfMiddleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($pdfMiddleware[0]->key)->toBe('customer-export-pdf-'.$this->customer->id)
        ->and($xlsxMiddleware)->toBe([])
        ->and((new GenerateCustomerOrdersExport($pdf->id))->timeout)->toBeLessThanOrEqual(40);
});

it('marks the export failed when the job itself fails', function (): void {
    $export = Export::factory()->forCustomer($this->customer)->status(ExportStatus::Processing)->create();

    (new GenerateCustomerOrdersExport($export->id))->failed(new RuntimeException('timeout'));

    expect($export->refresh()->status)->toBe(ExportStatus::Failed);
});

it('renders the e-mail with the link, the format and the end of the period', function (): void {
    $html = (new CustomerExportReadyMail('Анна', 'PDF', 'https://shop.example/account/exports/abc', '15.11.2026 10:00'))->render();

    expect($html)->toContain('https://shop.example/account/exports/abc')->toContain('PDF')->toContain('15.11.2026 10:00')->toContain('Анна');
});

it('formats file sizes in Russian units', function (): void {
    expect(FileSize::format(512))->toBe('512 Б')
        ->and(FileSize::format(1536))->toBe('1,5 КБ')
        ->and(FileSize::format(2048))->toBe('2 КБ')
        ->and(FileSize::format(5 * 1024 * 1024))->toBe('5 МБ');
});

it('leaves no file behind when the build fails and removes a stale partial file of a failed export', function (): void {
    $export = Export::factory()->forCustomer($this->customer, ExportType::CustomerOrdersXlsx, [])->create(['params' => ['from' => '2026-01-01']]);
    $directory = 'exports/customers/'.$this->customer->uuid;

    app(CustomerExportService::class)->generate($export);

    expect(Storage::disk('local')->allFiles($directory))->toBe([]);

    // A killed worker leaves the temporary file; the failure handler of the job removes it.
    $dead = Export::factory()->forCustomer($this->customer)->status(ExportStatus::Processing)->create();
    Storage::disk('local')->put($directory.'/'.$dead->uuid.'.pdf.part', 'half');

    (new GenerateCustomerOrdersExport($dead->id))->failed(new RuntimeException('timeout'));

    expect(Storage::disk('local')->allFiles($directory))->toBe([]);
});

it('cleanup also deletes the files of failed exports', function (): void {
    $export = Export::factory()->forCustomer($this->customer)->status(ExportStatus::Failed)->create(['expires_at' => now()->subMinute()]);
    $path = 'exports/customers/'.$this->customer->uuid.'/'.$export->uuid.'.pdf';
    Storage::disk('local')->put($path.'.part', 'half');
    Storage::disk('local')->put($path, 'orphan');

    app(CustomerExportService::class)->purgeExpired();

    expect(Export::query()->find($export->id))->toBeNull()
        ->and(Storage::disk('local')->allFiles('exports/customers/'.$this->customer->uuid))->toBe([]);
});

it('writes nothing and sends nothing when the customer was anonymized before the job ran', function (): void {
    Mail::fake();
    setSettings(CustomerSettings::class, ['export_email_link' => true]);
    cxMakeOrders($this->customer, 1);
    $export = Export::factory()->forCustomer($this->customer, ExportType::CustomerOrdersXlsx, cxExportParams())->create();
    $this->customer->forceFill(['anonymized_at' => now()])->save();

    app(CustomerExportService::class)->generate($export);

    expect($export->refresh()->status)->toBe(ExportStatus::Failed)
        ->and($export->file_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles('exports'))->toBe([])
        ->and(CustomerNotification::query()->where('customer_id', $this->customer->id)->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('drops the file and the e-mail when the customer is anonymized while the job runs', function (): void {
    Mail::fake();
    setSettings(CustomerSettings::class, ['export_email_link' => true]);
    cxMakeOrders($this->customer, 1);
    $export = Export::factory()->forCustomer($this->customer, ExportType::CustomerOrdersXlsx, cxExportParams())->create();
    $customer = $this->customer;
    $fired = false;

    DB::listen(function ($query) use (&$fired, $customer): void {
        if (! $fired && preg_match('/from [`"]orders[`"]/', $query->sql) === 1) {
            $fired = true;
            app(CustomerAnonymizer::class)->anonymize($customer->fresh());
        }
    });

    app(CustomerExportService::class)->generate($export);

    expect($fired)->toBeTrue()
        ->and(Storage::disk('local')->allFiles('exports'))->toBe([])
        ->and(Export::query()->where('status', ExportStatus::Completed->value)->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('shows the waiting time on the 429 page (NF-53)', function (): void {
    RateLimiter::clear('customer-export-download:'.$this->customer->id);
    setSettings(CustomerSettings::class, ['export_downloads_per_hour' => 1]);
    Storage::disk('local')->put('exports/customers/c/w.xlsx', 'x');
    $export = Export::factory()->forCustomer($this->customer, ExportType::CustomerOrdersXlsx)->status(ExportStatus::Completed)
        ->create(['file_path' => 'exports/customers/c/w.xlsx', 'file_size' => 1]);

    $this->get(route('customer.exports.download', $export->uuid))->assertOk();
    $response = $this->get(route('customer.exports.download', $export->uuid))->assertStatus(429);

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and($response->getContent())->toMatch('/Повторите через \d+ (мин|сек|ч)\./');
});
