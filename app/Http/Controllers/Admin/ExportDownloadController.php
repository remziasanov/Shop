<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Export;
use App\Services\Export\ExportManager;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a finished export file from the private disk (EX-03). Owner or administrator only.
 */
final class ExportDownloadController
{
    public function __invoke(Export $export, ExportManager $manager): StreamedResponse
    {
        Gate::authorize('download', $export);

        abort_unless($manager->fileExists($export), 404);

        /** @var string $path */
        $path = $export->file_path;

        $extension = $export->type->extension();
        $contentType = $extension === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        // A customer export is stored under its identifier: the download gets a neutral name with the right extension.
        $name = $export->customer_id !== null ? 'customer-orders-'.$export->id.'.'.$extension : basename($path);

        return Storage::disk(ExportManager::DISK)->download($path, $name, ['Content-Type' => $contentType]);
    }
}
