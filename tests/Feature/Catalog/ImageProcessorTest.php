<?php

declare(strict_types=1);

use App\Exceptions\ImageProcessingException;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Media\ImageProcessor;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
    $this->dir = sys_get_temp_dir().'/shop-img-'.uniqid();
    mkdir($this->dir);
    $this->processor = app(ImageProcessor::class);
});

afterEach(function (): void {
    foreach (glob($this->dir.'/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

function makeImage(string $dir, string $type = 'jpeg', int $w = 2000, int $h = 1000, string $name = 'src'): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 80, 40));
    imagefilledrectangle($im, 0, 0, intdiv($w, 4), intdiv($h, 2), imagecolorallocate($im, 10, 200, 10));
    $path = "$dir/$name.$type";

    match ($type) {
        'jpeg' => imagejpeg($im, $path, 90),
        'png' => imagepng($im, $path),
        'gif' => imagegif($im, $path),
        'webp' => imagewebp($im, $path),
    };

    return $path;
}

it('creates three variants within size limits without upscaling', function (): void {
    $product = Product::factory()->create();
    $data = $this->processor->process(makeImage($this->dir), $product);
    $ext = $this->processor->outputExtension();

    expect($data['width'])->toBe(1600)->and($data['height'])->toBe(800)
        ->and($data['path_large'])->toMatch('#^products/'.$product->id.'/[0-9a-f-]{36}-1600\.'.$ext.'$#')
        ->and($data['path_medium'])->toEndWith('-800.'.$ext)
        ->and($data['path_small'])->toEndWith('-400.'.$ext);

    foreach (['large' => 1600, 'medium' => 800, 'small' => 400] as $key => $max) {
        Storage::disk('public')->assertExists($data["path_$key"]);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($data["path_$key"]));
        expect($w)->toBe($max)->and($h)->toBe($max / 2);
    }

    // A small source is never enlarged.
    $small = $this->processor->process(makeImage($this->dir, 'png', 300, 200, 'small'), $product);
    expect($small['width'])->toBe(300)->and($small['height'])->toBe(200);
    [$w] = getimagesizefromstring(Storage::disk('public')->get($small['path_large']));
    expect($w)->toBe(300);
});

it('writes webp when GD supports it', function (): void {
    expect($this->processor->outputExtension())->toBe((imagetypes() & IMG_WEBP) ? 'webp' : 'jpg');
});

it('accepts png, gif and webp', function (string $type): void {
    $data = $this->processor->process(makeImage($this->dir, $type, 900, 600), Product::factory()->create());

    expect($data['width'])->toBe(900);
})->with(['png', 'gif', 'webp']);

it('applies the EXIF orientation', function (): void {
    if (! function_exists('exif_read_data')) {
        $this->markTestSkipped('exif extension is not available');
    }

    $path = makeImage($this->dir, 'jpeg', 400, 200);
    // Inject an EXIF APP1 segment with Orientation = 6 (rotate 90 CW).
    $exif = "Exif\0\0".'MM'.pack('n', 42).pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('nn', 6, 0).pack('N', 0);
    $jpeg = file_get_contents($path);
    $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    file_put_contents($path, $jpeg);

    $data = $this->processor->process($path, Product::factory()->create());

    expect($data['width'])->toBe(200)->and($data['height'])->toBe(400);
});

it('rejects corrupt files', function (): void {
    $path = $this->dir.'/broken.jpg';
    file_put_contents($path, str_repeat('not an image, just text. ', 80));
    $product = Product::factory()->create();

    expect(fn () => $this->processor->process($path, $product))->toThrow(ImageProcessingException::class, __('catalog.errors.image_corrupt'));

    // Valid header, truncated body.
    $valid = makeImage($this->dir, 'jpeg', 800, 800, 'trunc');
    file_put_contents($valid, substr((string) file_get_contents($valid), 0, 600));
    expect(fn () => $this->processor->process($valid, $product))->toThrow(ImageProcessingException::class);

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects svg and other types', function (): void {
    $svg = $this->dir.'/a.svg';
    file_put_contents($svg, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>');
    $product = Product::factory()->create();

    expect(fn () => $this->processor->process($svg, $product))->toThrow(ImageProcessingException::class, __('catalog.errors.image_unsupported_type'));

    $bmp = $this->dir.'/a.bmp';
    $im = imagecreatetruecolor(10, 10);
    imagebmp($im, $bmp);
    expect(fn () => $this->processor->process($bmp, $product))->toThrow(ImageProcessingException::class);
});

it('rejects missing and oversized files', function (): void {
    $product = Product::factory()->create();

    expect(fn () => $this->processor->process($this->dir.'/nope.jpg', $product))->toThrow(ImageProcessingException::class);

    $big = $this->dir.'/big.jpg';
    $h = fopen($big, 'w');
    ftruncate($h, ImageProcessor::MAX_BYTES + 1);
    fclose($h);
    expect(fn () => $this->processor->process($big, $product))->toThrow(ImageProcessingException::class, __('catalog.errors.image_too_big', ['max' => 10]));
});

it('attaches an image to a product with defaults (AD-52)', function (): void {
    $product = Product::factory()->create(['name' => 'Кресло']);

    $first = $this->processor->attachToProduct($product, makeImage($this->dir, 'jpeg', 500, 500, 'one'), null, sha1('a'));
    $second = $this->processor->attachToProduct($product, makeImage($this->dir, 'jpeg', 500, 500, 'two'), 'Вид сбоку');

    expect($first->alt)->toBe('Кресло')->and($first->sort_order)->toBe(0)->and($first->source_hash)->toBe(sha1('a'))
        ->and($second->alt)->toBe('Вид сбоку')->and($second->sort_order)->toBe(1)
        ->and($product->images()->count())->toBe(2);
});

it('can delete the source after attaching and enforces the 15 photo limit', function (): void {
    $product = Product::factory()->create();
    $src = makeImage($this->dir, 'jpeg', 100, 100, 'tmp');

    $this->processor->attachToProduct($product, $src, deleteSource: true);
    expect(file_exists($src))->toBeFalse();

    ProductImage::factory()->count(14)->create(['product_id' => $product->id]);

    expect(fn () => $this->processor->attachToProduct($product, makeImage($this->dir, 'jpeg', 100, 100, 'x')))
        ->toThrow(ImageProcessingException::class);
});

it('deletes all files when a ProductImage is deleted', function (): void {
    $product = Product::factory()->create();
    $image = $this->processor->attachToProduct($product, makeImage($this->dir, 'jpeg', 900, 900));
    $paths = [$image->path_large, $image->path_medium, $image->path_small];

    foreach ($paths as $path) {
        Storage::disk('public')->assertExists($path);
    }

    $image->delete();

    foreach ($paths as $path) {
        Storage::disk('public')->assertMissing($path);
    }
});

it('keeps files on soft delete and removes them on force delete (AD-44)', function (): void {
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    $image = $this->processor->attachToProduct($product, makeImage($this->dir, 'jpeg', 900, 900, 'a'));
    $otherImage = $this->processor->attachToProduct($other, makeImage($this->dir, 'jpeg', 900, 900, 'b'));

    $product->delete();
    Storage::disk('public')->assertExists($image->path_large);

    $product->restore();
    $product->forceDelete();

    Storage::disk('public')->assertMissing($image->path_large);
    Storage::disk('public')->assertMissing($image->path_small);
    expect(Storage::disk('public')->directories('products'))->toBe(['products/'.$other->id]);
    Storage::disk('public')->assertExists($otherImage->path_large);
    expect(ProductImage::count())->toBe(1);
});

it('builds urls and srcset from real widths', function (): void {
    $image = ProductImage::factory()->create(['width' => 1600, 'height' => 800]);

    expect($image->urlLarge())->toBe(Storage::disk('public')->url($image->path_large))
        ->and($image->srcset())->toBe(
            Storage::disk('public')->url($image->path_small).' 400w, '
            .Storage::disk('public')->url($image->path_medium).' 800w, '
            .Storage::disk('public')->url($image->path_large).' 1600w'
        );

    $tiny = ProductImage::factory()->create(['width' => 300, 'height' => 200]);
    expect($tiny->srcset())->toBe(Storage::disk('public')->url($tiny->path_small).' 300w');
});
