<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Import;
use App\Services\Import\ImportErrorReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of an import (IM-12, IM-13): the original file, or the original sheet with the extra «Ошибка» column
 * (built on demand from the source file and the issues table). Private disk, staff only.
 */
final class ImportFileDownloadController
{
    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __invoke(Import $import, string $kind, ImportErrorReport $report): StreamedResponse
    {
        Gate::authorize('download', $import);

        $disk = Storage::disk('local');

        abort_unless($import->file_path !== '' && $disk->exists($import->file_path), 404);

        if ($kind === 'source') {
            $extension = strtolower(pathinfo($import->file_path, PATHINFO_EXTENSION));

            return $disk->download($import->file_path, $this->safeName($import->original_name, $extension), [
                'Content-Type' => $extension === 'csv' ? 'text/csv; charset=UTF-8' : self::XLSX,
            ]);
        }

        abort_unless($kind === 'errors' && $import->status->isFinished() && $import->error_count > 0, 404);

        $path = $report->build($import);
        $base = pathinfo($import->original_name, PATHINFO_FILENAME);

        return $disk->download($path, $this->safeName($base.' — '.__('import_process.error_column'), 'xlsx'), [
            'Content-Type' => self::XLSX,
        ]);
    }

    private function safeName(string $name, string $extension): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '', $name));
        $stem = pathinfo($name, PATHINFO_EXTENSION) === $extension ? pathinfo($name, PATHINFO_FILENAME) : $name;

        return ($stem === '' ? 'import' : $stem).'.'.$extension;
    }
}
