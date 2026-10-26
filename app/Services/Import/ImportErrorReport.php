<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\ImportIssueLevel;
use App\Models\Import;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelWriter;

/**
 * The original sheet with an extra «Ошибка» column for correction and re-upload (IM-12).
 *
 * Streams the source file row by row (any size, CSV included) into `imports/{id}/errors.xlsx`; rows that had
 * errors get the joined messages ("Колонка: сообщение; …") in the last column, all other rows are copied as is.
 */
final class ImportErrorReport
{
    public function __construct(private readonly ImportReader $reader) {}

    /**
     * Build (or rebuild) the report.
     *
     * @return string path relative to the private `local` disk, e.g. `imports/12/errors.xlsx`
     */
    public function build(Import $import): string
    {
        $disk = Storage::disk('local');
        $relative = 'imports/'.$import->id.'/errors.xlsx';
        $disk->makeDirectory('imports/'.$import->id);
        $target = $disk->path($relative);
        @unlink($target);

        $messages = $this->messages($import);
        $writer = SimpleExcelWriter::create($target)->noHeaderRow();
        $first = true;

        try {
            foreach ($this->reader->open($disk->path($import->file_path), $import->sheet) as $number => $cells) {
                if ($first) {
                    $writer->addRow([...$cells, __('import_process.error_column')]);
                    $first = false;

                    continue;
                }

                $writer->addRow([...$cells, $messages[$number] ?? null]);
            }
        } finally {
            $writer->close();
        }

        return $relative;
    }

    /**
     * Row number => joined error messages of the product sheet.
     *
     * @return array<int, string>
     */
    private function messages(Import $import): array
    {
        $messages = [];

        $query = $import->issues()
            ->where('level', ImportIssueLevel::Error->value)
            ->where(fn ($q) => $q->whereNull('column')->orWhere('column', 'not like', CategorySheetImporter::columnPrefix().'%'))
            ->orderBy('row_number')
            ->orderBy('id');

        foreach ($query->cursor() as $issue) {
            $text = ($issue->column !== null && $issue->column !== '' ? $issue->column.': ' : '').$issue->message;
            $messages[$issue->row_number] = isset($messages[$issue->row_number]) ? $messages[$issue->row_number].'; '.$text : $text;
        }

        return $messages;
    }
}
