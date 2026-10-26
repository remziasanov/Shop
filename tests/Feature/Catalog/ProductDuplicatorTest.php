<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\Catalog\ProductDuplicator;
use App\Services\Media\ImageProcessor;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

function duplicatorSourceImage(): string
{
    $path = sys_get_temp_dir().'/dup-'.uniqid().'.jpg';
    $im = imagecreatetruecolor(900, 600);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
    imagejpeg($im, $path, 90);

    return $path;
}

it('creates an inactive copy with the -copy sku and the same name and characteristics', function (): void {
    $source = Product::factory()->create([
        'sku' => 'AB-100',
        'name' => 'Стол офисный',
        'is_active' => true,
        'attributes' => [['name' => 'Цвет', 'value' => 'Белый'], ['name' => 'Цвет', 'value' => 'Серый']],
        'stock_qty' => 7,
    ]);

    $copy = app(ProductDuplicator::class)->duplicate($source);

    expect($copy->id)->not->toBe($source->id)
        ->and($copy->sku)->toBe('AB-100-copy')
        ->and($copy->name)->toBe('Стол офисный')
        ->and($copy->is_active)->toBeFalse()
        ->and($copy->slug)->not->toBe($source->slug)->and($copy->slug)->not->toBe('')
        ->and($copy->specs())->toBe($source->specs())
        ->and($copy->stock_qty)->toBe(7)
        ->and($copy->category_id)->toBe($source->category_id)
        ->and($source->fresh()->is_active)->toBeTrue();
});

it('picks the next free copy sku, trashed products included', function (): void {
    $source = Product::factory()->create(['sku' => 'X-1']);
    $duplicator = app(ProductDuplicator::class);

    $first = $duplicator->duplicate($source);
    $first->delete();
    $second = $duplicator->duplicate($source);
    $third = $duplicator->duplicate($source);

    expect($first->sku)->toBe('X-1-copy')
        ->and($second->sku)->toBe('X-1-copy-2')
        ->and($third->sku)->toBe('X-1-copy-3');
});

it('copies photo files to new paths', function (): void {
    $source = Product::factory()->create();
    $processor = app(ImageProcessor::class);
    $processor->attachToProduct($source, duplicatorSourceImage(), 'Первое', null, true);
    $processor->attachToProduct($source, duplicatorSourceImage(), 'Второе', null, true);

    $copy = app(ProductDuplicator::class)->duplicate($source);
    $copyImages = $copy->images()->get();
    $sourceImages = $source->images()->get();

    expect($copyImages)->toHaveCount(2)
        ->and($copyImages->pluck('alt')->all())->toBe(['Первое', 'Второе'])
        ->and($copyImages->pluck('sort_order')->all())->toBe($sourceImages->pluck('sort_order')->all());

    foreach ($copyImages as $i => $image) {
        foreach (['path_large', 'path_medium', 'path_small'] as $column) {
            expect($image->{$column})->toStartWith('products/'.$copy->id.'/')
                ->and($image->{$column})->not->toBe($sourceImages[$i]->{$column});
            Storage::disk('public')->assertExists($image->{$column});
            Storage::disk('public')->assertExists($sourceImages[$i]->{$column});
        }
    }
});

it('deleting the copy permanently leaves the source photos intact', function (): void {
    $source = Product::factory()->create();
    app(ImageProcessor::class)->attachToProduct($source, duplicatorSourceImage(), null, null, true);
    $copy = app(ProductDuplicator::class)->duplicate($source);

    $copy->forceDelete();

    Storage::disk('public')->assertExists($source->images()->first()->path_large);
    Storage::disk('public')->assertMissing('products/'.$copy->id);
});

it('skips photos whose files are missing instead of failing', function (): void {
    $source = Product::factory()->create();
    $image = app(ImageProcessor::class)->attachToProduct($source, duplicatorSourceImage(), null, null, true);
    Storage::disk('public')->delete($image->path_medium);

    $copy = app(ProductDuplicator::class)->duplicate($source);

    expect($copy->images()->count())->toBe(0)->and($copy->exists)->toBeTrue();
});
