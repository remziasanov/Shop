<?php

declare(strict_types=1);

namespace App\Http\Controllers\Storefront\Account;

use App\Enums\ExportStatus;
use App\Exceptions\ExportThrottledException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Storefront\Concerns\RendersAccountPages;
use App\Http\Requests\Account\CustomerExportRequest;
use App\Services\Export\CustomerExportService;
use App\Settings\CustomerSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Order history exports of the cabinet (CX-01, CX-04…CX-09): the request from the history page, the list «Мои выгрузки»
 * and the download by UUID. A foreign or unknown UUID is a 404 (IDOR), an expired or deleted file a 410 with the
 * advice to make a new one, limits answer 429.
 */
class ExportController extends Controller
{
    use RendersAccountPages;

    public function index(Request $request, CustomerExportService $exports, CustomerSettings $settings): View
    {
        $customer = $this->currentCustomer($request);

        return $this->page('exports', __('customer_export.list.title'), [
            'exports' => $exports->recent($customer),
            'service' => $exports,
            'hours' => $settings->export_retention_hours,
        ]);
    }

    public function store(CustomerExportRequest $request, CustomerExportService $exports): Response|RedirectResponse
    {
        $customer = $this->currentCustomer($request);
        $type = $request->exportType();
        $params = $request->exportParams();

        try {
            $exports->ensureCanRequest($customer);
        } catch (ExportThrottledException $e) {
            abort(429, $e->getMessage(), ['Retry-After' => (string) $e->retryAfter]);
        }

        $plan = $exports->plan($customer, $type, $params);

        if (! $plan->isAllowed()) {
            return redirect()->to(route('customer.orders').'#export')->withInput()->withErrors(['export' => (string) $plan->error]);
        }

        $exports->hitRequest($customer);

        if ($plan->sync) {
            $path = $exports->buildTemporary($customer, $type, $params);

            return response()->download($path, $this->fileName($params['from'], $params['until'], $type->extension()), [
                'Content-Type' => $type->extension() === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        $exports->queue($customer, $type, $params);

        return redirect()->route('customer.exports')->with('status', __('customer_export.flash.queued'));
    }

    public function download(Request $request, string $uuid, CustomerExportService $exports): Response|RedirectResponse
    {
        $customer = $this->currentCustomer($request);
        $export = $exports->find($customer, $uuid);

        abort_if($export === null, 404);

        try {
            $exports->hitDownload($customer);
        } catch (ExportThrottledException $e) {
            abort(429, $e->getMessage(), ['Retry-After' => (string) $e->retryAfter]);
        }

        if ($export->status === ExportStatus::Failed) {
            return redirect()->route('customer.exports')->withErrors(['export' => $export->failure_message ?? __('customer_export.errors.generation_failed')]);
        }

        if ($export->status !== ExportStatus::Completed) {
            return redirect()->route('customer.exports')->withErrors(['export' => __('customer_export.errors.not_ready')]);
        }

        abort_unless($exports->isDownloadable($export), 410, __('customer_export.errors.gone'));

        /** @var string $path */
        $path = $export->file_path;
        /** @var array{from?: string, until?: string} $params */
        $params = $export->params ?? [];

        return Storage::disk(CustomerExportService::DISK)->download(
            $path,
            $this->fileName((string) ($params['from'] ?? 'history'), (string) ($params['until'] ?? ''), $export->type->extension()),
            ['Content-Type' => $export->type->extension() === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function fileName(string $from, string $until, string $extension): string
    {
        return 'orders-'.trim($from.'_'.$until, '_').'.'.$extension;
    }
}
