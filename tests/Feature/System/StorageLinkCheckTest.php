<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Services\System\EnvironmentChecker;
use Illuminate\Support\Facades\File;

/*
 * shop:doctor «Ссылка public/storage» (12.4): the check follows the link, so a Windows junction (neither a link nor a
 * directory for PHP) passes and a broken link fails.
 */

beforeEach(function (): void {
    $this->publicPath = public_path();
    $this->sandbox = sys_get_temp_dir().'/shop-storage-link-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->sandbox.'/public');
    File::ensureDirectoryExists($this->sandbox.'/target');
    app()->usePublicPath($this->sandbox.'/public');
});

afterEach(function (): void {
    app()->usePublicPath($this->publicPath);
    $link = $this->sandbox.'/public/storage';

    // A link or junction is removed without touching its target.
    if (is_link($link) || (PHP_OS_FAMILY === 'Windows' && @readlink($link) !== false)) {
        @rmdir($link) || @unlink($link);
    }

    File::deleteDirectory($this->sandbox);
});

function storageLinkCheck(): object
{
    return collect(app(EnvironmentChecker::class)->run())->firstWhere('key', 'storage_link');
}

it('errors when public/storage is missing', function (): void {
    expect(storageLinkCheck()->status)->toBe(CheckStatus::Error);
});

it('passes for a plain directory', function (): void {
    File::ensureDirectoryExists($this->sandbox.'/public/storage');

    expect(storageLinkCheck()->status)->toBe(CheckStatus::Ok);
});

it('passes for a symlink and errors once its target is gone', function (): void {
    if (! @symlink($this->sandbox.'/target', $this->sandbox.'/public/storage')) {
        $this->markTestSkipped('Symlinks are not available here.');
    }

    expect(storageLinkCheck()->status)->toBe(CheckStatus::Ok);

    File::deleteDirectory($this->sandbox.'/target');
    clearstatcache();

    expect(storageLinkCheck()->status)->toBe(CheckStatus::Error);
});

it('passes for a Windows junction', function (): void {
    if (PHP_OS_FAMILY !== 'Windows') {
        $this->markTestSkipped('Junctions exist on Windows only.');
    }

    $link = str_replace('/', '\\', $this->sandbox.'/public/storage');
    $target = str_replace('/', '\\', $this->sandbox.'/target');
    exec('mklink /J '.escapeshellarg($link).' '.escapeshellarg($target).' 2>NUL', $output, $code);

    if ($code !== 0) {
        $this->markTestSkipped('Could not create a junction.');
    }

    expect(storageLinkCheck()->status)->toBe(CheckStatus::Ok);
});
