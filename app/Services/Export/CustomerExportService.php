<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\CustomerNotificationType;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Exceptions\CustomerThrottledException;
use App\Exceptions\ExportThrottledException;
use App\Jobs\GenerateCustomerOrdersExport;
use App\Mail\CustomerExportReadyMail;
use App\Models\Customer;
use App\Models\Export;
use App\Models\Order;
use App\Services\Customers\CustomerNotificationService;
use App\Settings\CustomerSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The order history export of a customer (CX-04…CX-09, TZ 18.6): limits and the sync / queue decision, the rate limits,
 * the journal row, the file on the private disk (`exports/customers/{customer_uuid}/`), the notification and e-mail, the
 * lookup for the download (always scoped by customer) and the cleanup of expired files.
 *
 * @phpstan-import-type Params from CustomerOrdersExporter
 */
final class CustomerExportService
{
    public const DISK = 'local';

    /** Suffix of the file under construction; it becomes the final name only when the build succeeded. */
    private const PARTIAL_SUFFIX = '.part';

    private const HOUR = 3600;

    /** Table rows (requests plus items) up to which a PDF is built in the web request: the request has 128 MB for everything. */
    public const SYNC_PDF_MAX_ROWS = 300;

    public function __construct(
        private readonly CustomerOrdersExporter $exporter,
        private readonly OrderPdfRenderer $pdf,
        private readonly CustomerNotificationService $notifications,
        private readonly CustomerSettings $settings,
    ) {}

    /**
     * Counts, applies the hard limits (CX-05) and decides between the request and the queue (CX-04).
     *
     * @param  Params  $params
     */
    public function plan(Customer $customer, ExportType $type, array $params): ExportPlan
    {
        $orders = $this->exporter->countOrders($customer, $params);

        if ($orders === 0) {
            return new ExportPlan(0, false, __('customer_export.errors.empty'));
        }

        if ($type === ExportType::CustomerOrdersPdf) {
            $limit = $params['with_items'] ? $this->settings->export_max_pdf_items : $this->settings->export_max_pdf;

            if ($orders > $limit) {
                return new ExportPlan($orders, false, __($params['with_items'] ? 'customer_export.errors.pdf_items_too_many' : 'customer_export.errors.pdf_too_many', ['limit' => $limit, 'count' => $orders]));
            }

            $rows = $orders + ($params['with_items'] ? $this->exporter->countItems($customer, $params) : 0);

            if ($rows > $this->settings->export_max_pdf_rows) {
                return new ExportPlan($orders, false, __('customer_export.errors.pdf_rows_too_many', ['limit' => $this->settings->export_max_pdf_rows, 'count' => $rows]));
            }

            $syncLimit = $params['with_items'] ? $this->settings->export_sync_pdf_items_max : $this->settings->export_sync_pdf_max;

            return new ExportPlan($orders, $orders <= $syncLimit && $rows <= self::SYNC_PDF_MAX_ROWS);
        }

        $rows = $orders + ($params['with_items'] ? $this->exporter->countItems($customer, $params) : 0);

        if ($rows > $this->settings->export_max_xlsx_rows) {
            return new ExportPlan($orders, false, __('customer_export.errors.xlsx_too_many', ['limit' => $this->settings->export_max_xlsx_rows, 'count' => $rows]));
        }

        return new ExportPlan($orders, $orders <= $this->settings->export_sync_xlsx_max);
    }

    /**
     * CX-09: the request limit and the number of unfinished exports. Counted only for accepted requests ({@see hitRequest()}).
     *
     * @throws ExportThrottledException
     */
    public function ensureCanRequest(Customer $customer): void
    {
        $key = $this->requestKey($customer);

        if (RateLimiter::tooManyAttempts($key, max(1, $this->settings->export_requests_per_hour))) {
            $wait = max(1, RateLimiter::availableIn($key));

            throw new ExportThrottledException(__('customer_export.errors.too_many_requests', ['time' => CustomerThrottledException::humanTime($wait)]), $wait);
        }

        $pending = Export::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Processing->value])
            ->count();

        if ($pending >= max(1, $this->settings->export_max_pending)) {
            throw new ExportThrottledException(__('customer_export.errors.too_many_pending', ['count' => $pending, 'time' => CustomerThrottledException::humanTime(60)]), 60);
        }
    }

    public function hitRequest(Customer $customer): void
    {
        RateLimiter::hit($this->requestKey($customer), self::HOUR);
    }

    /**
     * CX-09: a download (an export file or an order blank) counts against the hourly limit.
     *
     * @throws ExportThrottledException
     */
    public function hitDownload(Customer $customer): void
    {
        $key = 'customer-export-download:'.$customer->id;

        if (RateLimiter::tooManyAttempts($key, max(1, $this->settings->export_downloads_per_hour))) {
            $wait = max(1, RateLimiter::availableIn($key));

            throw new ExportThrottledException(__('customer_export.errors.too_many_downloads', ['time' => CustomerThrottledException::humanTime($wait)]), $wait);
        }

        RateLimiter::hit($key, self::HOUR);
    }

    /**
     * Builds the file at once, for the web request (CX-04). The caller sends it and deletes it.
     *
     * @param  Params  $params
     * @return string absolute path of a temporary file
     */
    public function buildTemporary(Customer $customer, ExportType $type, array $params): string
    {
        $directory = storage_path('app/private/tmp/customer-exports');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.Str::uuid().'.'.$type->extension();

        try {
            $this->build($customer, $type, $params, $path);
        } catch (Throwable $e) {
            File::delete($path);

            throw $e;
        }

        return $path;
    }

    /**
     * Creates the journal row of a large export and queues the job (CX-04).
     *
     * @param  Params  $params
     */
    public function queue(Customer $customer, ExportType $type, array $params): Export
    {
        $export = Export::query()->create([
            'customer_id' => $customer->id,
            'type' => $type,
            'status' => ExportStatus::Queued,
            'params' => $params,
            'rows' => 0,
            'expires_at' => now()->addHours(max(1, $this->settings->export_retention_hours)),
        ]);

        GenerateCustomerOrdersExport::dispatch($export->id);

        return $export;
    }

    /**
     * The job body (CX-06): builds the file on the private disk, then notifies the customer. A failure is kept in the
     * row (status `failed`, a readable message) and reported; it does not rethrow, a repeat is a new request.
     */
    public function generate(Export $export): void
    {
        $customer = $export->customer;

        if ($customer === null || $export->status === ExportStatus::Completed || ! $export->type->isCustomerOrders()) {
            return;
        }

        // Already `processing`: an earlier run of this job died (memory, killed worker) without a word. A repeat would die
        // the same way, so the export is closed as failed and the customer asks again with a narrower period.
        if ($export->status === ExportStatus::Processing) {
            $this->markFailed($export, __('customer_export.errors.generation_failed'));

            return;
        }

        $export->update(['status' => ExportStatus::Processing, 'failure_message' => null]);

        // The customer may have been anonymized or deleted while the job waited in the queue.
        if ($this->customerIsGone($export)) {
            $this->abandon($export);

            return;
        }

        $relative = $this->relativePath($customer, $export);
        $temporary = $relative.self::PARTIAL_SUFFIX;
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($relative));

        try {
            // Written under a temporary name: a timeout or a crash must never leave a file that looks finished.
            /** @var Params $params */
            $params = $export->params ?? [];
            $rows = $this->build($customer, $export->type, $params, $disk->path($temporary));
        } catch (Throwable $e) {
            report($e);
            $disk->delete($temporary);

            $this->markFailed($export, __('customer_export.errors.generation_failed'));

            return;
        }

        // The build takes up to 40 seconds: anonymization may have run meanwhile and removed the row and the directory.
        if ($this->customerIsGone($export)) {
            $disk->delete($temporary);
            $this->abandon($export);

            return;
        }

        $disk->move($temporary, $relative);

        $updated = Export::query()
            ->whereKey($export->id)
            ->where('status', ExportStatus::Processing->value)
            ->update([
                'status' => ExportStatus::Completed->value,
                'file_path' => $relative,
                'file_size' => $disk->size($relative),
                'rows' => $rows,
                // The period of 24 hours runs from the moment the file is ready, not from the queue time (CX-07).
                'expires_at' => now()->addHours(max(1, $this->settings->export_retention_hours)),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            $disk->delete($relative);

            return;
        }

        $this->notifyReady($export->refresh(), $customer->refresh());
    }

    /**
     * True when the row or its customer no longer exists, or the customer was anonymized (152-FZ): nothing is written
     * or sent for such an export.
     *
     * @phpstan-impure
     */
    private function customerIsGone(Export $export): bool
    {
        $fresh = Export::query()->find($export->id);
        $customer = $fresh?->customer_id === null ? null : Customer::query()->find($fresh->customer_id);

        return $fresh === null || $customer === null || $customer->anonymized_at !== null;
    }

    /**
     * Closes an export whose customer is gone: failed, its files removed, no notice and no e-mail.
     */
    private function abandon(Export $export): void
    {
        $this->deleteFiles($export);

        Export::query()->whereKey($export->id)->update([
            'status' => ExportStatus::Failed->value,
            'failure_message' => mb_substr(__('customer_export.errors.generation_failed'), 0, 1000),
            'file_path' => null,
            'file_size' => null,
        ]);
    }

    private function relativePath(Customer $customer, Export $export): string
    {
        return 'exports/customers/'.$customer->uuid.'/'.($export->uuid ?? $export->id).'.'.$export->type->extension();
    }

    /**
     * Removes the finished file and the partial one of an export (also of a failed one, which has no `file_path`).
     */
    private function deleteFiles(Export $export): void
    {
        $disk = Storage::disk(self::DISK);

        if ($export->file_path !== null) {
            $disk->delete($export->file_path);
        }

        $customer = $export->customer_id === null ? null : Customer::query()->find($export->customer_id, ['id', 'uuid']);

        if ($customer !== null && $export->type->isCustomerOrders()) {
            $relative = $this->relativePath($customer, $export);
            $disk->delete([$relative, $relative.self::PARTIAL_SUFFIX]);
        }
    }

    /**
     * Failure of the job (timeout, crash) or of the generation: the status, a readable message, a cabinet notice.
     */
    public function markFailed(Export $export, string $message): void
    {
        if ($export->status === ExportStatus::Completed) {
            return;
        }

        $this->deleteFiles($export);
        $export->update(['status' => ExportStatus::Failed, 'failure_message' => mb_substr($message, 0, 1000), 'file_path' => null, 'file_size' => null]);

        if ($export->customer !== null && $export->customer->anonymized_at === null) {
            $this->notifications->notify(
                $export->customer,
                CustomerNotificationType::ExportReady,
                __('customer_export.notification.failed_title'),
                $message,
                route('customer.exports', [], false),
                'export-failed:'.$export->id,
            );
        }
    }

    /**
     * The export of this customer, or null: a foreign or unknown identifier looks the same (CX-07, IDOR).
     */
    public function find(Customer $customer, string $uuid): ?Export
    {
        if (! Str::isUuid($uuid)) {
            return null;
        }

        return Export::query()->where('customer_id', $customer->id)->where('uuid', $uuid)->first();
    }

    /**
     * CX-08: the last exports of the customer.
     *
     * @return Collection<int, Export>
     */
    public function recent(Customer $customer, int $limit = 20): Collection
    {
        return Export::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Can the file be downloaded now: done, not expired, still on the disk (CX-07).
     */
    public function isDownloadable(Export $export): bool
    {
        return $export->status === ExportStatus::Completed
            && $export->file_path !== null
            && ($export->expires_at === null || $export->expires_at->isFuture())
            && Storage::disk(self::DISK)->exists($export->file_path);
    }

    /**
     * A finished export whose file is gone or whose period is over («Файл удалён»).
     */
    public function isGone(Export $export): bool
    {
        return $export->status === ExportStatus::Completed && ! $this->isDownloadable($export);
    }

    /**
     * CX-13: the administrator deletes the file; the row stays and its link answers 410.
     */
    public function deleteFile(Export $export): void
    {
        if ($export->file_path !== null) {
            Storage::disk(self::DISK)->delete($export->file_path);
        }

        $export->update(['file_path' => null, 'file_size' => null]);
    }

    /**
     * `shop:cleanup` (TZ 18.7): files and rows of customer exports past `expires_at`. Returns the number of removed rows.
     */
    public function purgeExpired(?float $deadline = null): int
    {
        $purged = 0;

        Export::query()
            ->whereNotNull('customer_id')
            ->where('expires_at', '<', now())
            ->lazyById(200)
            ->takeUntil(fn (): bool => $deadline !== null && microtime(true) >= $deadline)
            ->each(function (Export $export) use (&$purged): void {
                $this->deleteFiles($export);
                $export->delete();
                $purged++;
            });

        return $purged;
    }

    /**
     * 152-FZ: the exports of an anonymized customer hold personal data: their files and rows are removed at once.
     */
    public function purgeForCustomer(Customer $customer): int
    {
        Storage::disk(self::DISK)->deleteDirectory('exports/customers/'.$customer->uuid);

        return Export::query()->where('customer_id', $customer->id)->delete();
    }

    /**
     * «Бланк заказа / счёт» of one order as PDF bytes (CX-10), for the cabinet and the admin.
     */
    public function orderDocument(Order $order): string
    {
        return $this->pdf->document($order);
    }

    /**
     * @param  Params  $params
     */
    private function build(Customer $customer, ExportType $type, array $params, string $path): int
    {
        if ($type === ExportType::CustomerOrdersXlsx) {
            return $this->exporter->writeXlsx($customer, $params, $path);
        }

        $withItems = $params['with_items'];
        $pdf = $this->pdf->history(
            $customer,
            ['from' => $params['from'], 'until' => $params['until']],
            $withItems,
            $this->exporter->chunks($customer, $params, $withItems),
            $this->describeFilters($params),
        );

        File::put($path, $pdf);

        return $this->exporter->countOrders($customer, $params);
    }

    /**
     * «Типы: заказ, резерв; статусы: новый» for the header of the PDF.
     *
     * @param  Params  $params
     */
    private function describeFilters(array $params): string
    {
        $parts = [];

        if ($params['types'] !== []) {
            $parts[] = __('pdf.history.types', ['list' => implode(', ', array_map(
                fn (string $type): string => OrderType::tryFrom($type)?->label() ?? $type,
                $params['types'],
            ))]);
        }

        if ($params['statuses'] !== []) {
            $parts[] = __('pdf.history.statuses', ['list' => implode(', ', array_map(
                fn (string $status): string => OrderStatus::tryFrom($status)?->label() ?? $status,
                $params['statuses'],
            ))]);
        }

        return implode('; ', $parts);
    }

    private function notifyReady(Export $export, Customer $customer): void
    {
        $expires = $export->expires_at ?? CarbonImmutable::now()->addDay();

        $this->notifications->notify(
            $customer,
            CustomerNotificationType::ExportReady,
            __('customer_export.notification.ready_title'),
            __('customer_export.notification.ready_body', [
                'format' => $export->type === ExportType::CustomerOrdersPdf ? 'PDF' : 'Excel',
                'until' => $expires->format('d.m.Y H:i'),
            ]),
            route('customer.exports', [], false),
            'export-ready:'.$export->id,
        );

        if ($this->settings->export_email_link && $customer->anonymized_at === null && filled($customer->email)) {
            Mail::to($customer->email)->send(new CustomerExportReadyMail(
                $customer->name,
                $export->type === ExportType::CustomerOrdersPdf ? 'PDF' : 'Excel',
                route('customer.exports.download', $export->uuid),
                $expires->format('d.m.Y H:i'),
            ));
        }
    }

    private function requestKey(Customer $customer): string
    {
        return 'customer-export-request:'.$customer->id;
    }
}
