<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Enums\ImportStatus;
use App\Models\Cart;
use App\Models\Export;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Writes a file on the disk and back-dates its modification time.
 */
function stale(string $path, int $hoursOld, string $disk = 'local'): void
{
    Storage::disk($disk)->put($path, 'x');
    touch(Storage::disk($disk)->path($path), time() - $hoursOld * 3600);
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('deletes old carts and keeps fresh ones', function (): void {
    $old = Cart::factory()->create();
    $fresh = Cart::factory()->create();
    Cart::query()->whereKey($old->id)->update(['updated_at' => now()->subDays(31)]);

    $this->artisan('shop:cleanup')->assertSuccessful();

    expect(Cart::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(Cart::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

it('purges files of old imports, keeps the rows and spares fresh and running imports', function (): void {
    $old = Import::factory()->status(ImportStatus::Completed)->create(['file_path' => 'imports/1/source.csv']);
    $fresh = Import::factory()->status(ImportStatus::Completed)->create(['file_path' => 'imports/2/source.csv']);
    $running = Import::factory()->status(ImportStatus::Processing)->create(['file_path' => 'imports/3/source.csv']);
    Import::query()->whereIn('id', [$old->id, $running->id])->update(['created_at' => now()->subDays(40)]);

    foreach ([$old, $fresh, $running] as $import) {
        Storage::disk('local')->put('imports/'.$import->id.'/source.csv', 'a;b');
    }

    $this->artisan('shop:cleanup')->assertSuccessful();

    expect(Storage::disk('local')->exists('imports/'.$old->id.'/source.csv'))->toBeFalse()
        ->and(Storage::disk('local')->exists('imports/'.$fresh->id.'/source.csv'))->toBeTrue()
        ->and(Storage::disk('local')->exists('imports/'.$running->id.'/source.csv'))->toBeTrue()
        ->and($old->fresh()->files_purged_at)->not->toBeNull()
        ->and($fresh->fresh()->files_purged_at)->toBeNull()
        ->and($running->fresh()->files_purged_at)->toBeNull();

    // Idempotent: the second run changes nothing.
    $stamp = $old->fresh()->files_purged_at;
    $this->artisan('shop:cleanup')->assertSuccessful();
    expect($old->fresh()->files_purged_at->equalTo($stamp))->toBeTrue();
});

it('removes abandoned uploads and temporary files older than a day only', function (): void {
    stale('imports/uploads/old.xlsx', 30);
    stale('imports/uploads/new.xlsx', 1);
    stale('tmp/product-photos/old.jpg', 48);
    stale('tmp/product-photos/new.jpg', 2);
    stale('tmp/category-images/old.jpg', 25);
    stale('tmp/home-images/old.jpg', 25);
    stale('livewire-tmp/old.tmp', 30);
    stale('livewire-tmp/new.tmp', 1);

    $this->artisan('shop:cleanup')->assertSuccessful();

    $disk = Storage::disk('local');
    expect($disk->exists('imports/uploads/old.xlsx'))->toBeFalse()
        ->and($disk->exists('imports/uploads/new.xlsx'))->toBeTrue()
        ->and($disk->exists('tmp/product-photos/old.jpg'))->toBeFalse()
        ->and($disk->exists('tmp/product-photos/new.jpg'))->toBeTrue()
        ->and($disk->exists('tmp/category-images/old.jpg'))->toBeFalse()
        ->and($disk->exists('tmp/home-images/old.jpg'))->toBeFalse()
        ->and($disk->exists('livewire-tmp/old.tmp'))->toBeFalse()
        ->and($disk->exists('livewire-tmp/new.tmp'))->toBeTrue();
});

it('purges export files older than 7 days', function (): void {
    $old = Export::factory()->status(ExportStatus::Completed)->create(['file_path' => 'exports/1/catalog.xlsx']);
    $fresh = Export::factory()->status(ExportStatus::Completed)->create(['file_path' => 'exports/2/catalog.xlsx']);
    Export::query()->whereKey($old->id)->update(['created_at' => now()->subDays(8)]);
    Storage::disk('local')->put('exports/'.$old->id.'/catalog.xlsx', 'x');
    Storage::disk('local')->put('exports/'.$fresh->id.'/catalog.xlsx', 'x');

    $this->artisan('shop:cleanup')
        ->expectsOutputToContain('Очищены файлы выгрузок: 1')
        ->assertSuccessful();

    expect(Storage::disk('local')->exists('exports/'.$old->id.'/catalog.xlsx'))->toBeFalse()
        ->and(Storage::disk('local')->exists('exports/'.$fresh->id.'/catalog.xlsx'))->toBeTrue()
        ->and($old->fresh()->file_path)->toBeNull();
});

it('hides the source download of a purged import', function (): void {
    $import = Import::factory()->status(ImportStatus::Completed)->create(['file_path' => 'imports/9/source.csv']);
    Storage::disk('local')->put('imports/9/source.csv', 'a;b');
    $import->update(['files_purged_at' => now()]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.imports.download', ['import' => $import, 'kind' => 'source']))
        ->assertNotFound();
});
