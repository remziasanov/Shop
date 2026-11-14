<?php

declare(strict_types=1);

use App\Exceptions\ImageProcessingException;
use App\Services\Media\AppIconGenerator;
use App\Settings\AppearanceSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

/** Pixel of a stored PNG as [r, g, b, a(0-127)]. */
function pwaPixel(string $path, int $x, int $y): array
{
    $image = imagecreatefromstring(Storage::disk('public')->get($path));
    $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));

    return [$rgba['red'], $rgba['green'], $rgba['blue'], $rgba['alpha']];
}

it('creates 192, 512, 512 maskable and 180 apple icons with the hash in the name (MB-21)', function () {
    $hash = app(AppIconGenerator::class)->generate(pwaPng(700, '#ff0000'), '#00ff00');

    $expected = [
        "app-icons/icon-192-{$hash}.png" => 192,
        "app-icons/icon-512-{$hash}.png" => 512,
        "app-icons/icon-512-maskable-{$hash}.png" => 512,
        "app-icons/apple-touch-icon-{$hash}.png" => 180,
    ];

    foreach ($expected as $path => $side) {
        Storage::disk('public')->assertExists($path);
        $size = getimagesizefromstring(Storage::disk('public')->get($path));

        expect([$size[0], $size[1], $size['mime']])->toBe([$side, $side, 'image/png']);
    }

    expect(Storage::disk('public')->files('app-icons'))->toHaveCount(4);
});

it('keeps 10 percent padding on the background color in the maskable icon (MB-21)', function () {
    $hash = app(AppIconGenerator::class)->generate(pwaPng(600, '#ff0000'), '#00ff00');
    $maskable = "app-icons/icon-512-maskable-{$hash}.png";

    // The corner and the point 40 px from the edge (inside the 51 px padding) are the background, the center is the icon.
    expect(array_slice(pwaPixel($maskable, 2, 2), 0, 3))->toBe([0, 255, 0])
        ->and(array_slice(pwaPixel($maskable, 40, 256), 0, 3))->toBe([0, 255, 0])
        ->and(array_slice(pwaPixel($maskable, 60, 256), 0, 3))->toBe([255, 0, 0])
        ->and(array_slice(pwaPixel($maskable, 256, 256), 0, 3))->toBe([255, 0, 0]);

    // The plain 512 icon is the picture edge to edge; the apple icon has no transparency.
    expect(array_slice(pwaPixel("app-icons/icon-512-{$hash}.png", 2, 2), 0, 3))->toBe([255, 0, 0]);
});

it('derives different names from the icon and from the background color (MB-21)', function () {
    $generator = app(AppIconGenerator::class);
    $png = pwaPng();

    $a = $generator->generate($png, '#ffffff');
    $b = $generator->generate($png, '#000000');
    $c = $generator->generate(pwaPng(600, '#123456'), '#000000');

    expect($b)->not->toBe($a)->and($c)->not->toBe($b)
        ->and($generator->generate($png, '#fff'))->toBe($a);
});

it('removes the files of the previous set when the icon changes (MB-21)', function () {
    $generator = app(AppIconGenerator::class);
    $old = $generator->generate(pwaPng(600, '#ff0000'), '#ffffff');
    $new = $generator->generate(pwaPng(600, '#0000ff'), '#ffffff');

    Storage::disk('public')->assertMissing("app-icons/icon-192-{$old}.png");
    Storage::disk('public')->assertExists("app-icons/icon-192-{$new}.png");
    expect(Storage::disk('public')->files('app-icons'))->toHaveCount(4);
});

it('regenerates the set when the background color changes and purges it without an icon (MB-21)', function () {
    $settings = enablePwa(['pwa_background_color' => '#ffffff']);
    $first = $settings->pwa_icons_hash;

    $settings->pwa_background_color = '#101010';
    $settings->save();
    app(AppIconGenerator::class)->sync($settings);

    expect($settings->pwa_icons_hash)->not->toBe($first)
        ->and(app(AppearanceSettings::class)->pwa_icons_hash)->toBe($settings->pwa_icons_hash);
    Storage::disk('public')->assertMissing("app-icons/icon-192-{$first}.png");

    // Same inputs: nothing is rewritten.
    $hash = $settings->pwa_icons_hash;
    app(AppIconGenerator::class)->sync($settings);
    expect($settings->pwa_icons_hash)->toBe($hash);

    $settings->pwa_icon = null;
    $settings->save();
    app(AppIconGenerator::class)->sync($settings);

    expect($settings->pwa_icons_hash)->toBeNull()->and(Storage::disk('public')->files('app-icons'))->toBe([]);
});

it('rejects SVG, other formats, small and non-square images (MB-21, NF-11)', function (string $contents, string $message) {
    expect(fn () => app(AppIconGenerator::class)->generate($contents, '#ffffff'))
        ->toThrow(ImageProcessingException::class, $message);
    expect(Storage::disk('public')->files('app-icons'))->toBe([]);
})->with([
    'svg' => ['<svg xmlns="http://www.w3.org/2000/svg" width="600" height="600"><rect width="600" height="600"/></svg>', 'PNG, JPG и WebP'],
    'text' => ['hello', 'PNG, JPG и WebP'],
    'gif' => [fn () => base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'PNG, JPG и WebP'],
    'too small' => [fn () => pwaPng(511), '512×512'],
    // Only the header is read: a PNG header that announces 5000×5000 (25 MP) is over the own 4096×4096 limit.
    'too many pixels' => [function () {
        $ihdr = pack('NNCCCCC', 5000, 5000, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
    }, '4096×4096'],
    'not square' => [function () {
        $image = imagecreatetruecolor(800, 600);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }, 'квадратной'],
]);

it('accepts JPEG and WebP sources (MB-21)', function () {
    $image = imagecreatetruecolor(512, 512);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    expect(app(AppIconGenerator::class)->generate($jpeg, '#ffffff'))->toHaveLength(12);

    ob_start();
    imagewebp($image);
    $webp = (string) ob_get_clean();

    expect(app(AppIconGenerator::class)->generate($webp, '#ffffff'))->toHaveLength(12);
});
