<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Enums\MediaSource;
use App\Exceptions\ImageFetchDeadlineException;
use App\Exceptions\ImageFetchException;
use App\Models\Import;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Turns the photo references of an import row into local files (IM-02, IM-11).
 *
 *  - `http(s)://…` — downloaded through {@see ImageFetcher} into `imports/{id}/downloads/` (one download per URL
 *    per import, also across queue jobs; failures are remembered too);
 *  - a file name — looked up case-insensitively by base name (directories in the reference are ignored) in
 *    (a) the ZIP uploaded with the import (`media_source = zip`, `media_path` = path on the private disk), extracted
 *        lazily into `imports/{id}/media/` under generated names (entry paths are never used for writing, entries
 *        with `..`/absolute paths are ignored: no zip-slip; ≤ 10 MB per entry, ≤ configured total, ≤ 20 000 entries), or
 *    (b) a directory or ZIP inside `storage/app/import-media/` chosen by name (`media_source = server_dir`,
 *        `media_path` = its name); the real path must stay inside that folder, symbolic links are skipped.
 *
 * {@see hashFor()} gives the `source_hash` stored with the imported photo so a re-import does not repeat the work.
 */
final class MediaResolver
{
    public const SERVER_DIRECTORY = 'import-media';

    public const MAX_ENTRY_BYTES = 10 * 1024 * 1024;

    public const MAX_ZIP_ENTRIES = 20000;

    public const MAX_INDEXED_FILES = 50000;

    public const DEFAULT_TOTAL_MB = 512;

    /** @var array<string, array{index: int, size: int}>|null lower-cased base name => ZIP entry */
    private ?array $zipIndex = null;

    /** @var array<string, string>|null lower-cased base name => absolute path */
    private ?array $dirIndex = null;

    private ?int $extractedBytes = null;

    private ?ZipArchive $zip = null;

    public function __construct(
        private readonly Import $import,
        private readonly ImageFetcher $fetcher,
    ) {}

    public function __destruct()
    {
        $this->zip?->close();
    }

    public static function isUrl(string $reference): bool
    {
        return preg_match('#^https?://#i', trim($reference)) === 1;
    }

    /**
     * `source_hash` of a reference: sha1(url) or sha1('file:'.lower-cased base name).
     */
    public static function hashFor(string $reference): string
    {
        $reference = trim($reference);

        return self::isUrl($reference)
            ? sha1($reference)
            : sha1('file:'.self::normalizedName($reference));
    }

    /**
     * Absolute path of a readable local file for the reference.
     *
     * @param  float|null  $deadline  microtime(true) by which the step must be finished (limits the download)
     *
     * @throws ImageFetchException with a translated message
     * @throws ImageFetchDeadlineException when the deadline leaves no time for the download
     */
    public function resolve(string $reference, ?float $deadline = null): string
    {
        $reference = trim($reference);

        return self::isUrl($reference) ? $this->download($reference, $deadline) : $this->locate($reference);
    }

    /**
     * Remove everything this class wrote for the import (downloads and extracted archive files).
     */
    public static function cleanup(Import $import): void
    {
        $disk = Storage::disk('local');
        $disk->deleteDirectory('imports/'.$import->id.'/downloads');
        $disk->deleteDirectory('imports/'.$import->id.'/media');
    }

    private function download(string $url, ?float $deadline): string
    {
        $disk = Storage::disk('local');
        $directory = 'imports/'.$this->import->id.'/downloads';
        $disk->makeDirectory($directory);

        $hash = sha1($url);
        $file = $disk->path($directory.'/'.$hash.'.img');
        $failure = $disk->path($directory.'/'.$hash.'.fail');

        if (is_file($file) && filesize($file) > 0) {
            return $file;
        }

        if (is_file($failure)) {
            throw new ImageFetchException((string) file_get_contents($failure));
        }

        $partial = $file.'.'.bin2hex(random_bytes(4)).'.part';

        try {
            $this->fetcher->fetch($url, $partial, $deadline);
        } catch (ImageFetchException $e) {
            @file_put_contents($failure, $e->getMessage());

            throw $e;
        }

        if (! @rename($partial, $file)) {
            @unlink($partial);

            throw new ImageFetchException(__('import_process.fetch.storage'));
        }

        return $file;
    }

    private function locate(string $reference): string
    {
        $name = self::normalizedName($reference);
        $display = basename(str_replace('\\', '/', $reference));

        if ($name === '') {
            throw new ImageFetchException(__('import_process.media.not_found', ['name' => $reference]));
        }

        return match ($this->import->media_source) {
            MediaSource::Zip => $this->fromZip($name, $display, $this->uploadedZipPath()),
            MediaSource::ServerDir => $this->fromServerDirectory($name, $display),
            default => throw new ImageFetchException(__('import_process.media.no_archive', ['name' => $display])),
        };
    }

    private function uploadedZipPath(): ?string
    {
        $path = $this->import->media_path;

        if ($path === null || $path === '' || str_contains($path, '..')) {
            return null;
        }

        $absolute = Storage::disk('local')->path($path);

        return is_file($absolute) ? $absolute : null;
    }

    private function fromServerDirectory(string $name, string $display): string
    {
        $chosen = $this->serverEntry();

        if ($chosen === null) {
            throw new ImageFetchException(__('import_process.media.dir_invalid'));
        }

        if (is_file($chosen)) {
            return $this->fromZip($name, $display, $chosen);
        }

        $this->dirIndex ??= $this->indexDirectory($chosen);

        return $this->dirIndex[$name] ?? throw new ImageFetchException(__('import_process.media.not_found', ['name' => $display]));
    }

    /**
     * The chosen directory or ZIP inside storage/app/import-media (null when missing or outside of it).
     */
    private function serverEntry(): ?string
    {
        $chosen = $this->import->media_path;

        if ($chosen === null || trim($chosen) === '' || str_contains($chosen, "\0")) {
            return null;
        }

        $base = realpath(storage_path('app/'.self::SERVER_DIRECTORY));

        if ($base === false) {
            return null;
        }

        $real = realpath($base.DIRECTORY_SEPARATOR.ltrim(str_replace('\\', '/', $chosen), '/'));

        if ($real === false || ! str_starts_with($real, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        if (is_file($real) && strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'zip') {
            return null;
        }

        return $real;
    }

    /**
     * @return array<string, string>
     */
    private function indexDirectory(string $directory): array
    {
        $index = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (count($index) >= self::MAX_INDEXED_FILES) {
                break;
            }

            if ($file->isLink() || ! $file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $real = $file->getRealPath();

            if ($real === false || ! str_starts_with($real, $directory.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $index[mb_strtolower($file->getFilename(), 'UTF-8')] ??= $real;
        }

        return $index;
    }

    private function fromZip(string $name, string $display, ?string $zipPath): string
    {
        if ($zipPath === null) {
            throw new ImageFetchException(__('import_process.media.no_archive', ['name' => $display]));
        }

        $this->openZip($zipPath);

        $entry = $this->zipIndex[$name] ?? null;

        if ($entry === null || $this->zip === null) {
            throw new ImageFetchException(__('import_process.media.not_found', ['name' => $display]));
        }

        $disk = Storage::disk('local');
        $directory = 'imports/'.$this->import->id.'/media';
        $disk->makeDirectory($directory);

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,5}$/', $extension) === 1 ? $extension : 'bin';
        $target = $disk->path($directory.'/'.sha1($name).'.'.$extension);

        if (is_file($target) && filesize($target) > 0) {
            return $target;
        }

        if ($entry['size'] > self::MAX_ENTRY_BYTES) {
            throw new ImageFetchException(__('import_process.media.zip_entry_too_big', ['name' => $display, 'max' => intdiv(self::MAX_ENTRY_BYTES, 1048576)]));
        }

        $limit = $this->totalLimit();

        if ($this->extractedBytes($disk->path($directory)) + $entry['size'] > $limit) {
            throw new ImageFetchException(__('import_process.media.zip_total_too_big', ['max' => intdiv($limit, 1048576)]));
        }

        $in = $this->zip->getStream($this->zip->getNameIndex($entry['index']) ?: '');
        $partial = $target.'.'.bin2hex(random_bytes(4)).'.part';
        $out = $in === false ? false : fopen($partial, 'wb');

        if ($in === false || $out === false) {
            if (is_resource($in)) {
                fclose($in);
            }

            throw new ImageFetchException(__('import_process.media.zip_extract_failed', ['name' => $display]));
        }

        // The declared size can lie: never copy more than the per-entry limit.
        $written = stream_copy_to_stream($in, $out, self::MAX_ENTRY_BYTES + 1);
        fclose($in);
        fclose($out);

        if ($written === false || $written > self::MAX_ENTRY_BYTES) {
            @unlink($partial);

            throw new ImageFetchException(__('import_process.media.zip_entry_too_big', ['name' => $display, 'max' => intdiv(self::MAX_ENTRY_BYTES, 1048576)]));
        }

        if (! @rename($partial, $target)) {
            @unlink($partial);

            throw new ImageFetchException(__('import_process.media.zip_extract_failed', ['name' => $display]));
        }

        $this->extractedBytes = ($this->extractedBytes ?? 0) + $written;

        return $target;
    }

    private function openZip(string $zipPath): void
    {
        if ($this->zipIndex !== null) {
            return;
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new ImageFetchException(__('import_process.media.zip_unreadable'));
        }

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            $zip->close();

            throw new ImageFetchException(__('import_process.media.zip_too_many', ['max' => self::MAX_ZIP_ENTRIES]));
        }

        $index = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false || ! self::isSafeEntry($stat['name'])) {
                continue;
            }

            $key = self::normalizedName($stat['name']);

            if ($key !== '' && ! str_starts_with($key, '.')) {
                $index[$key] ??= ['index' => $i, 'size' => (int) $stat['size']];
            }
        }

        $this->zip = $zip;
        $this->zipIndex = $index;
    }

    /**
     * A regular file entry whose name cannot escape a directory (no `..`, absolute path, drive letter, NUL).
     */
    private static function isSafeEntry(string $name): bool
    {
        if ($name === '' || str_ends_with($name, '/') || str_contains($name, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:/', $normalized) === 1 || str_starts_with($normalized, '__MACOSX/')) {
            return false;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function extractedBytes(string $directory): int
    {
        if ($this->extractedBytes !== null) {
            return $this->extractedBytes;
        }

        $total = 0;

        if (is_dir($directory)) {
            foreach (new \DirectoryIterator($directory) as $file) {
                if ($file->isFile()) {
                    $total += $file->getSize();
                }
            }
        }

        return $this->extractedBytes = $total;
    }

    private function totalLimit(): int
    {
        return max(1, (int) config('shop.import_media_max_total_mb', self::DEFAULT_TOTAL_MB)) * 1048576;
    }

    private static function normalizedName(string $reference): string
    {
        return mb_strtolower(basename(str_replace('\\', '/', trim($reference))), 'UTF-8');
    }
}
