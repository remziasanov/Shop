<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Import;
use App\Services\Import\ImportAnalyzer;
use App\Services\Import\ImportRunner;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Helpers to run an import end to end in tests (queue faked: steps are driven by hand).
 */
final class ImportHarness
{
    /**
     * Copy the file into place, create the import and analyze it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function analyzed(string $sourcePath, array $attributes = []): Import
    {
        $import = Import::factory()->create($attributes + ['file_path' => 'pending']);
        $relative = 'imports/'.$import->id.'/source.'.pathinfo($sourcePath, PATHINFO_EXTENSION);

        Storage::disk('local')->put($relative, (string) file_get_contents($sourcePath));
        $import->update(['file_path' => $relative]);

        return app(ImportAnalyzer::class)->analyze($import->fresh());
    }

    /**
     * Start and process until the runner reports there is nothing left.
     */
    public static function run(Import $import, int $maxSteps = 2000): Import
    {
        $runner = app(ImportRunner::class);
        $runner->start($import);

        for ($i = 0; $i < $maxSteps && $runner->processNext($import->refresh(), 30.0); $i++);

        return $import->refresh();
    }

    /**
     * @return string JPEG bytes
     */
    public static function jpeg(int $width = 120, int $height = 80, int $red = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, $red, 90, 40));
        ob_start();
        imagejpeg($image, null, 80);

        return (string) ob_get_clean();
    }

    /**
     * Write a ZIP with the given entries (name => bytes) and return its path.
     *
     * @param  array<string, string>  $entries
     */
    public static function zip(array $entries): string
    {
        $path = ImportFiles::tempPath('zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);

        foreach ($entries as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }

        $zip->close();

        return $path;
    }

    /**
     * Store a ZIP on the private disk as the archive of the import.
     */
    public static function attachZip(Import $import, string $zipPath): void
    {
        $relative = 'imports/'.$import->id.'/media.zip';
        Storage::disk('local')->put($relative, (string) file_get_contents($zipPath));
        $import->update(['media_source' => 'zip', 'media_path' => $relative]);
    }
}
