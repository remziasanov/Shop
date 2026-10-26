<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Export\ImportTemplateBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «Скачать шаблон» (IM-01): the template is generated on the fly into a temporary file.
 */
final class ImportTemplateController
{
    public function __invoke(ImportTemplateBuilder $builder): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'shoptpl');
        abort_if($path === false, 500);

        try {
            $builder->build($path);
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }

        return response()->download($path, __('export.template.file_name'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }
}
