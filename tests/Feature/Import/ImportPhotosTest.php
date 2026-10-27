<?php

declare(strict_types=1);

use App\Enums\ImportStatus;
use App\Enums\MediaSource;
use App\Exceptions\ImageFetchException;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportPhotos;
use App\Models\Category;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Import\HostResolver;
use App\Services\Import\ImageFetcher;
use App\Services\Import\ImportRunner;
use App\Services\Import\ImportStep;
use App\Services\Import\MediaResolver;
use App\Services\Media\CategoryImageImporter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeHostResolver;
use Tests\Support\ImportFiles;
use Tests\Support\ImportHarness;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake([ProcessImportChunk::class, ProcessImportPhotos::class]);
    app()->instance(HostResolver::class, new FakeHostResolver);
});

const PHOTO_HEADER = ['Артикул', 'Название', 'Категория', 'Цена', 'Фото'];

function photoImport(array $rows, array $attributes = []): Import
{
    return ImportHarness::analyzed(ImportFiles::csv([PHOTO_HEADER, ...$rows]), $attributes);
}

function jpegResponse2(int $red = 200): mixed
{
    return Http::response(ImportHarness::jpeg(120, 80, $red), 200, ['Content-Type' => 'image/jpeg']);
}

it('imports photos from an uploaded ZIP by file name, case-insensitively, first one is main', function () {
    $import = photoImport([['A-1', 'Стол', 'Мебель', '100', 'a2.JPG; photos/A1.jpg']]);
    ImportHarness::attachZip($import, ImportHarness::zip([
        'photos/a1.jpg' => ImportHarness::jpeg(300, 200, 10),
        'A2.jpg' => ImportHarness::jpeg(100, 100, 250),
    ]));

    $import = ImportHarness::run($import->refresh());
    $product = Product::firstOrFail();
    $images = $product->images()->get();

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($import->warning_count)->toBe(0)
        ->and($images)->toHaveCount(2)
        ->and($images[0]->source_hash)->toBe(sha1('file:a2.jpg'))
        ->and($images[1]->source_hash)->toBe(sha1('file:a1.jpg'))
        ->and($images[0]->sort_order)->toBe(0)
        ->and($images[1]->sort_order)->toBe(1);

    Storage::disk('public')->assertExists($images[0]->path_large);
    // Extracted files are removed once the import is finished.
    expect(Storage::disk('local')->exists('imports/'.$import->id.'/media'))->toBeFalse();
});

it('does not duplicate photos on a second run of the same file', function () {
    $zip = ImportHarness::zip(['a1.jpg' => ImportHarness::jpeg()]);
    $rows = [['A-1', 'Стол', 'Мебель', '100', 'a1.jpg']];

    $first = photoImport($rows);
    ImportHarness::attachZip($first, $zip);
    ImportHarness::run($first->refresh());
    $ids = ProductImage::pluck('id')->all();

    $second = photoImport($rows);
    ImportHarness::attachZip($second, $zip);
    ImportHarness::run($second->refresh());

    expect(ProductImage::pluck('id')->all())->toBe($ids);
});

it('rejects zip-slip entries and files that are not in the archive, as warnings', function () {
    $import = photoImport([['A-1', 'Стол', 'Мебель', '100', 'evil.jpg; ok.jpg; missing.jpg']]);
    ImportHarness::attachZip($import, ImportHarness::zip([
        '../evil.jpg' => ImportHarness::jpeg(),
        '/abs/evil.jpg' => ImportHarness::jpeg(),
        'ok.jpg' => ImportHarness::jpeg(),
    ]));

    $import = ImportHarness::run($import->refresh());

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and(Product::count())->toBe(1)
        ->and(ProductImage::count())->toBe(1)
        ->and($import->warning_count)->toBe(2)
        ->and($import->issues()->where('level', 'warning')->pluck('value')->sort()->values()->all())->toBe(['evil.jpg', 'missing.jpg']);

    expect(file_exists(Storage::disk('local')->path('evil.jpg')))->toBeFalse()
        ->and(file_exists(storage_path('evil.jpg')))->toBeFalse();
});

it('refuses a ZIP entry over 10 MB', function () {
    $import = photoImport([['A-1', 'Стол', 'Мебель', '100', 'big.jpg']]);
    ImportHarness::attachZip($import, ImportHarness::zip(['big.jpg' => str_repeat('x', MediaResolver::MAX_ENTRY_BYTES + 1)]));

    $import = ImportHarness::run($import->refresh());

    expect(ProductImage::count())->toBe(0)
        ->and($import->warning_count)->toBe(1)
        ->and($import->issues()->first()->message)->toContain('10');
});

it('refuses to extract more than the configured total', function () {
    config(['shop.import_media_max_total_mb' => 1]);
    $one = str_repeat('a', 700 * 1024);
    $import = photoImport([['A-1', 'Стол', 'Мебель', '100', 'a.jpg; b.jpg']]);
    ImportHarness::attachZip($import, ImportHarness::zip(['a.jpg' => $one, 'b.jpg' => $one]));

    $resolver = new MediaResolver($import->refresh(), app(ImageFetcher::class));

    // Not a valid image, but extraction itself succeeds for the first file and is refused for the second.
    expect($resolver->resolve('a.jpg'))->toBeFile()
        ->and(fn () => $resolver->resolve('b.jpg'))->toThrow(ImageFetchException::class);
});

it('warns when a file name is used without any archive', function () {
    $import = ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'a.jpg']]));

    expect(Product::count())->toBe(1)
        ->and($import->warning_count)->toBe(1);
});

it('downloads the same URL once for several products', function () {
    Http::fake(['https://img.test/*' => fn () => jpegResponse2()]);

    $import = ImportHarness::run(photoImport([
        ['A-1', 'Стол', 'Мебель', '100', 'https://img.test/shared.jpg'],
        ['A-2', 'Стул', 'Мебель', '100', 'https://img.test/shared.jpg; https://img.test/own.jpg'],
    ]));

    Http::assertSentCount(2);
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and(ProductImage::count())->toBe(3)
        ->and(Product::where('sku', 'A-2')->first()->images()->count())->toBe(2)
        ->and(ProductImage::where('source_hash', sha1('https://img.test/shared.jpg'))->count())->toBe(2)
        // downloads are cleaned up at the end
        ->and(Storage::disk('local')->exists('imports/'.$import->id.'/downloads'))->toBeFalse();
});

it('does not download again when the product already has the image from the same URL', function () {
    Http::fake(['https://img.test/*' => fn () => jpegResponse2()]);
    $rows = [['A-1', 'Стол', 'Мебель', '100', 'https://img.test/a.jpg']];

    ImportHarness::run(photoImport($rows));
    ImportHarness::run(photoImport($rows));

    Http::assertSentCount(1);
    expect(ProductImage::count())->toBe(1);
});

it('turns a non-image answer into a warning and still imports the product', function () {
    Http::fake([
        'https://img.test/page' => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        'https://img.test/ok.jpg' => fn () => jpegResponse2(),
    ]);

    $import = ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/page; https://img.test/ok.jpg']]));

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and(Product::count())->toBe(1)
        ->and(ProductImage::count())->toBe(1)
        ->and($import->warning_count)->toBe(1)
        ->and($import->issues()->first()->row_number)->toBe(2)
        ->and($import->issues()->first()->value)->toBe('https://img.test/page');
});

it('turns an image over 10 MB into a warning', function () {
    Http::fake(['*' => Http::response(str_repeat('a', ImageFetcher::MAX_BYTES + 1), 200, ['Content-Type' => 'image/jpeg'])]);

    $import = ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/huge.jpg']]));

    expect(Product::count())->toBe(1)
        ->and(ProductImage::count())->toBe(0)
        ->and($import->warning_count)->toBe(1);
});

it('does not fetch URLs that point to internal addresses', function () {
    app()->instance(HostResolver::class, new FakeHostResolver(['intranet.test' => ['10.0.0.5']]));
    Http::fake();

    $import = ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'http://intranet.test/a.jpg; http://169.254.169.254/x.jpg']]));

    Http::assertNothingSent();
    expect(Product::count())->toBe(1)->and($import->warning_count)->toBe(2);
});

it('remembers a failed URL for the rest of the import', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $import = ImportHarness::run(photoImport([
        ['A-1', 'Стол', 'Мебель', '100', 'https://img.test/gone.jpg'],
        ['A-2', 'Стул', 'Мебель', '100', 'https://img.test/gone.jpg'],
    ]));

    Http::assertSentCount(1);
    expect($import->warning_count)->toBe(2);
});

it('replaces existing photos only when the cell has photos', function () {
    Http::fake(['*' => fn () => jpegResponse2()]);
    $product = Product::factory()->create(['sku' => 'A-1']);
    $old = ProductImage::factory()->count(2)->create(['product_id' => $product->id]);

    // Empty cell: nothing changes.
    ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', '']]));
    expect($product->images()->count())->toBe(2);

    // Photo in the cell: old images are replaced.
    ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/new.jpg']]));
    $images = $product->images()->get();

    expect($images)->toHaveCount(1)
        ->and($images[0]->source_hash)->toBe(sha1('https://img.test/new.jpg'))
        ->and(ProductImage::whereIn('id', $old->pluck('id'))->count())->toBe(0);
});

it('keeps the old photos when every new one fails', function () {
    Http::fake(['*' => Http::response('', 404)]);
    $product = Product::factory()->create(['sku' => 'A-1']);
    ProductImage::factory()->create(['product_id' => $product->id]);

    ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/gone.jpg']]));

    expect($product->images()->count())->toBe(1);
});

it('appends photos in append mode and leaves them alone in skip mode', function () {
    Http::fake(['*' => fn () => jpegResponse2()]);
    $product = Product::factory()->create(['sku' => 'A-1']);
    $existing = ProductImage::factory()->create(['product_id' => $product->id, 'sort_order' => 0]);

    ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/new.jpg']], ['options' => ['photo_mode' => 'append']]));
    $images = $product->images()->get();

    expect($images)->toHaveCount(2)
        ->and($images[0]->id)->toBe($existing->id)
        ->and($images[1]->sort_order)->toBe(1);

    ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/other.jpg']], ['options' => ['photo_mode' => 'skip']]));

    expect($product->images()->count())->toBe(2);
});

it('does not touch photos in a dry run', function () {
    Http::fake();

    ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'https://img.test/a.jpg']], ['dry_run' => true]));

    Http::assertNothingSent();
});

it('imports from a folder in storage/app/import-media and refuses path traversal', function () {
    $base = storage_path('app/import-media');
    $folder = 'test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($base.'/'.$folder.'/sub');
    file_put_contents($base.'/'.$folder.'/sub/Pic.JPG', ImportHarness::jpeg());
    file_put_contents(storage_path('app/outside-'.$folder.'.jpg'), ImportHarness::jpeg());

    try {
        $import = photoImport([['A-1', 'Стол', 'Мебель', '100', 'pic.jpg']], ['media_source' => MediaSource::ServerDir, 'media_path' => $folder]);
        $import = ImportHarness::run($import);

        expect($import->warning_count)->toBe(0)
            ->and(ProductImage::count())->toBe(1)
            // the folder is never modified or removed by the import
            ->and(file_exists($base.'/'.$folder.'/sub/Pic.JPG'))->toBeTrue();

        foreach (['../outside-'.$folder.'.jpg', '..', '../..', '/etc', $folder.'/../..'] as $bad) {
            $attempt = photoImport([['B-'.$bad, 'Стол', 'Мебель', '100', 'outside-'.$folder.'.jpg']], ['media_source' => MediaSource::ServerDir, 'media_path' => $bad]);
            $attempt = ImportHarness::run($attempt);

            expect($attempt->warning_count)->toBe(1, 'path '.$bad);
        }

        expect(ProductImage::count())->toBe(1);
    } finally {
        File::deleteDirectory($base.'/'.$folder);
        @unlink(storage_path('app/outside-'.$folder.'.jpg'));
    }
});

it('imports from a ZIP placed in storage/app/import-media', function () {
    $base = storage_path('app/import-media');
    File::ensureDirectoryExists($base);
    $name = 'test-'.bin2hex(random_bytes(4)).'.zip';
    copy(ImportHarness::zip(['p.jpg' => ImportHarness::jpeg()]), $base.'/'.$name);

    try {
        $import = ImportHarness::run(photoImport([['A-1', 'Стол', 'Мебель', '100', 'p.jpg']], ['media_source' => MediaSource::ServerDir, 'media_path' => $name]));

        expect($import->warning_count)->toBe(0)->and(ProductImage::count())->toBe(1);
    } finally {
        @unlink($base.'/'.$name);
    }
});

it('stores photo tasks that do not fit into the time budget and finishes them later', function () {
    Http::fake(['*' => fn () => jpegResponse2()]);
    $import = photoImport([
        ['A-1', 'Стол', 'Мебель', '100', 'https://img.test/1.jpg'],
        ['A-2', 'Стул', 'Мебель', '100', 'https://img.test/2.jpg'],
    ]);
    $runner = app(ImportRunner::class);
    $runner->start($import);

    // No time left for photos: the data is imported, the photos are parked.
    expect($runner->step($import->refresh(), 0.0))->toBe(ImportStep::Chunk)
        ->and(Product::count())->toBe(2)
        ->and(ProductImage::count())->toBe(0);

    $pending = Storage::disk('local')->files('imports/'.$import->id);
    expect(collect($pending)->filter(fn ($f) => str_contains($f, 'photos-pending-')))->toHaveCount(1);

    // The import is not finished while photos wait.
    expect($runner->step($import->refresh(), 0.0))->toBe(ImportStep::Waiting)
        ->and($import->refresh()->status)->toBe(ImportStatus::Processing);

    expect($runner->step($import->refresh(), 30.0))->toBe(ImportStep::Photos)
        ->and(ProductImage::count())->toBe(2)
        ->and($runner->step($import->refresh(), 30.0))->toBe(ImportStep::Finished)
        ->and($import->refresh()->status)->toBe(ImportStatus::Completed);
});

it('imports a category photo from the categories sheet', function () {
    Http::fake(['*' => fn () => jpegResponse2()]);
    $path = ImportFiles::xlsx([
        'Товары' => [PHOTO_HEADER, ['A-1', 'Стол', 'Мебель', '100', '']],
        'Категории' => [['Путь', 'Фото'], ['Мебель', 'https://img.test/cat.jpg']],
    ]);

    $import = ImportHarness::run(ImportHarness::analyzed($path));
    $category = Category::where('name', 'Мебель')->firstOrFail();

    expect($import->warning_count)->toBe(0)
        ->and($category->image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($category->image_path);
});

it('does not reprocess an unchanged category photo on a re-import and reprocesses when the file is gone or the URL changed', function () {
    Http::fake(['*' => fn () => jpegResponse2()]);
    $sheet = fn (string $url): string => ImportFiles::xlsx([
        'Товары' => [PHOTO_HEADER, ['A-1', 'Стол', 'Мебель', '100', '']],
        'Категории' => [['Путь', 'Фото'], ['Мебель', $url]],
    ]);

    ImportHarness::run(ImportHarness::analyzed($sheet('https://img.test/cat.jpg')));
    $category = Category::where('name', 'Мебель')->firstOrFail();
    $first = $category->image_path;

    expect($category->image_source_hash)->toBe(sha1('https://img.test/cat.jpg'));
    Http::assertSentCount(1);

    ImportHarness::run(ImportHarness::analyzed($sheet('https://img.test/cat.jpg')));
    expect($category->refresh()->image_path)->toBe($first);
    Http::assertSentCount(1);

    Storage::disk('public')->delete($first);
    ImportHarness::run(ImportHarness::analyzed($sheet('https://img.test/cat.jpg')));
    expect($category->refresh()->image_path)->not->toBe($first);
    Http::assertSentCount(2);

    ImportHarness::run(ImportHarness::analyzed($sheet('https://img.test/other.jpg')));
    expect($category->refresh()->image_source_hash)->toBe(sha1('https://img.test/other.jpg'));
    Http::assertSentCount(3);
});

it('clears the category image source hash on a manual upload or removal', function () {
    $category = Category::factory()->create(['image_source_hash' => sha1('https://img.test/cat.jpg')]);
    $temporary = CategoryImageImporter::TEMP_DIRECTORY.'/manual.jpg';
    Storage::disk('local')->put($temporary, ImportHarness::jpeg());

    app(CategoryImageImporter::class)->apply($category, $temporary);
    expect($category->refresh()->image_path)->not->toBeNull()
        ->and($category->image_source_hash)->toBeNull();

    $category->update(['image_source_hash' => 'x']);
    app(CategoryImageImporter::class)->apply($category, null, true);
    expect($category->refresh()->image_path)->toBeNull()
        ->and($category->image_source_hash)->toBeNull();
});
