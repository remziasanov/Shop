<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Customer;
use App\Models\Order;
use App\Settings\GeneralSettings;
use App\Settings\LegalSettings;
use App\Support\Money;
use App\Support\OrderDiscountLines;
use Barryvdh\DomPDF\PDF;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * PDF documents of requests (CX-03, CX-10, CX-11) with dompdf: the history of a customer and the single order in
 * «document» mode — «Счёт на оплату» when the seller's bank requisites are filled, otherwise «Бланк заказа». One
 * template set (`resources/views/pdf`) serves the cabinet, the admin card and the background job.
 *
 * Everything that enters the HTML goes through Blade escaping; images are embedded as data URIs (no files or URLs in
 * the HTML), JavaScript, PHP and remote resources are off in `config/dompdf.php`. Page numbers are drawn on the canvas
 * (the `<script type="text/php">` way needs `isPhpEnabled`, which stays off).
 */
final class OrderPdfRenderer
{
    private const FONT = 'DejaVu Sans';

    /** Largest side of an embedded logo / signature image, pixels (keeps the document small and the GD memory low). */
    private const IMAGE_HEIGHT = 140;

    public function __construct(
        private readonly GeneralSettings $general,
        private readonly LegalSettings $legal,
    ) {}

    /**
     * The history of a customer: header with the requisites, customer, period, the table and the total (CX-03).
     *
     * @param  iterable<iterable<Order>>  $chunks  orders in chunks (items loaded when `$withItems`), memory-friendly
     * @param  array{from: string, until: string, types?: list<string>, statuses?: list<string>}  $period
     */
    public function history(Customer $customer, array $period, bool $withItems, iterable $chunks, string $filters = ''): string
    {
        $money = $this->moneyFormatter();
        $body = $withItems ? '' : view('pdf.partials.history-table-open')->render();

        $count = 0;
        $totalKopecks = 0;

        foreach ($chunks as $orders) {
            foreach ($orders as $order) {
                $count++;
                $totalKopecks += $order->total === null ? 0 : Money::fromRubles($order->total);
            }

            $body .= view($withItems ? 'pdf.partials.history-blocks' : 'pdf.partials.history-rows', ['orders' => $orders, 'money' => $money])->render();
        }

        if (! $withItems) {
            $body .= view('pdf.partials.history-table-close')->render();
        }

        $html = view('pdf.history', [
            'general' => $this->general,
            'legal' => $this->legal,
            'logo' => $this->imageDataUri($this->general->logo, 'public'),
            'customerLine' => implode(', ', array_filter([$customer->name, $customer->email, $customer->phone], fn (?string $part): bool => filled($part))),
            'period' => CarbonImmutable::parse($period['from'])->format('d.m.Y').' — '.CarbonImmutable::parse($period['until'])->format('d.m.Y'),
            'filters' => $filters,
            'generatedAt' => now()->format('d.m.Y H:i'),
            'body' => $body,
            'count' => $count,
            'periodTotal' => Money::format($totalKopecks),
        ])->render();

        return $this->toPdf($html);
    }

    /**
     * One order as a document (CX-10, CX-11, CX-13): invoice or blank.
     */
    public function document(Order $order): string
    {
        $order->loadMissing(['items', 'customer.company']);

        $total = $order->total === null ? null : Money::fromRubles($order->total);

        $html = view('pdf.document', [
            'general' => $this->general,
            'legal' => $this->legal,
            'logo' => $this->imageDataUri($this->general->logo, 'public'),
            'signature' => $this->imageDataUri($this->legal->signature_image, 'local'),
            'order' => $order,
            'title' => $this->documentTitle($order),
            'invoice' => $this->legal->hasBankRequisites(),
            'company' => $order->customer?->company,
            'money' => $this->moneyFormatter(),
            'discountLines' => OrderDiscountLines::for($order),
            'totalInWords' => $total === null ? null : (new NumberToWordsRu)->rubles($total),
        ])->render();

        return $this->toPdf($html);
    }

    /**
     * «Счёт на оплату № Z-00001 от 12.11.2026» or «Бланк заказа № Z-00001 от 12.11.2026» (CX-10).
     */
    public function documentTitle(Order $order): string
    {
        return __($this->legal->hasBankRequisites() ? 'pdf.document.invoice_title' : 'pdf.document.blank_title', [
            'number' => $order->number,
            'date' => $order->created_at?->format('d.m.Y') ?? '',
        ]);
    }

    /**
     * @return Closure(?string): string
     */
    private function moneyFormatter(): Closure
    {
        return fn (?string $value): string => $value === null || $value === '' ? '—' : Money::format(Money::fromRubles($value));
    }

    private function toPdf(string $html): string
    {
        File::ensureDirectoryExists((string) config('dompdf.options.font_dir'));
        File::ensureDirectoryExists((string) config('dompdf.options.temp_dir'));

        /** @var PDF $pdf */
        $pdf = app('dompdf.wrapper');
        $pdf->loadHTML($html)->setPaper('a4');

        $dompdf = $pdf->getDomPDF();
        $dompdf->render();

        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont(self::FONT, 'normal');
        $label = __('pdf.history.page', ['page' => '{PAGE_NUM}', 'pages' => '{PAGE_COUNT}']);
        $width = $dompdf->getFontMetrics()->getTextWidth(__('pdf.history.page', ['page' => '00', 'pages' => '00']), $font, 7);

        $canvas->page_text($canvas->get_width() - 36 - $width, $canvas->get_height() - 32, $label, $font, 7, [0.35, 0.35, 0.35]);

        return (string) $dompdf->output(['compress' => 1]);
    }

    /**
     * A small PNG data URI of a stored image, or null (no image, unreadable file, a format GD cannot read such as SVG).
     */
    private function imageDataUri(?string $path, string $disk): ?string
    {
        if (! filled($path)) {
            return null;
        }

        try {
            $storage = Storage::disk($disk);

            if (! $storage->exists($path)) {
                return null;
            }

            $image = (new ImageManager(new Driver))->read((string) $storage->get($path));
            $image->scaleDown(height: self::IMAGE_HEIGHT);

            return 'data:image/png;base64,'.base64_encode((string) $image->toPng());
        } catch (Throwable) {
            return null;
        }
    }
}
