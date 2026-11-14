<?php

declare(strict_types=1);

use App\Enums\ExportStatus;
use App\Models\Export;
use App\Services\Customers\CustomerAnonymizer;
use App\Services\Export\CustomerExportService;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * CX-07, TZ 18.7: `shop:cleanup` removes the files and the rows of customer exports past `expires_at`.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->customer = makeCustomer();
});

function cxExportWithFile(int $customerId, string $path, CarbonInterface $expiresAt): Export
{
    Storage::disk('local')->put($path, 'x');

    return Export::factory()->status(ExportStatus::Completed)->create([
        'user_id' => null,
        'customer_id' => $customerId,
        'file_path' => $path,
        'expires_at' => $expiresAt,
    ]);
}

it('removes the file and the row of an expired customer export and keeps a valid one', function (): void {
    $expired = cxExportWithFile($this->customer->id, 'exports/customers/c/old.pdf', now()->subMinute());
    $valid = cxExportWithFile($this->customer->id, 'exports/customers/c/new.pdf', now()->addHour());

    $this->artisan('shop:cleanup')->expectsOutputToContain('Удалено выгрузок покупателей с истёкшим сроком: 1')->assertSuccessful();

    expect(Export::query()->find($expired->id))->toBeNull()
        ->and(Storage::disk('local')->exists('exports/customers/c/old.pdf'))->toBeFalse()
        ->and(Export::query()->find($valid->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists('exports/customers/c/new.pdf'))->toBeTrue();
});

it('removes an expired export whose file is already gone and a failed one', function (): void {
    Export::factory()->forCustomer($this->customer)->status(ExportStatus::Failed)->create(['expires_at' => now()->subDay()]);
    Export::factory()->forCustomer($this->customer)->status(ExportStatus::Completed)->create(['file_path' => null, 'expires_at' => now()->subDay()]);

    expect(app(CustomerExportService::class)->purgeExpired())->toBe(2)->and(Export::query()->count())->toBe(0);
});

it('purges 24 hours after the creation with the default retention', function (): void {
    $export = cxExportWithFile($this->customer->id, 'exports/customers/c/a.pdf', now()->addDay());

    $this->travel(25)->hours();
    $this->artisan('shop:cleanup')->assertSuccessful();

    expect(Export::query()->find($export->id))->toBeNull()->and(Storage::disk('local')->exists('exports/customers/c/a.pdf'))->toBeFalse();
});

it('does not touch the exports of staff by the 24 hour rule', function (): void {
    Storage::disk('local')->put('exports/9/catalog.xlsx', 'x');
    $staff = Export::factory()->status(ExportStatus::Completed)->create(['file_path' => 'exports/9/catalog.xlsx', 'expires_at' => null]);

    $this->artisan('shop:cleanup')->assertSuccessful();

    expect(Export::query()->find($staff->id))->not->toBeNull()->and(Storage::disk('local')->exists('exports/9/catalog.xlsx'))->toBeTrue();
});

it('has the option --exports that does only the export step', function (): void {
    $expired = cxExportWithFile($this->customer->id, 'exports/customers/c/old.pdf', now()->subMinute());
    Storage::disk('local')->put('tmp/stale.jpg', 'x');
    touch(Storage::disk('local')->path('tmp/stale.jpg'), now()->subDays(3)->getTimestamp());

    $this->artisan('shop:cleanup --exports')
        ->expectsOutputToContain('Удалено выгрузок покупателей с истёкшим сроком: 1')
        ->doesntExpectOutputToContain('Удалено старых корзин')
        ->assertSuccessful();

    expect(Export::query()->find($expired->id))->toBeNull()
        ->and(Storage::disk('local')->exists('tmp/stale.jpg'))->toBeTrue();
});

it('is scheduled daily through the existing cleanup command', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'shop:cleanup'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('40 4 * * *');
});

it('deletes the exports of a customer with the customer (cascade, MySQL foreign key)', function (): void {
    $export = cxExportWithFile($this->customer->id, 'exports/customers/c/a.pdf', now()->addDay());

    $this->customer->delete();

    expect(Export::query()->find($export->id))->toBeNull();
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'The foreign key exists on MySQL only (SQLite cannot add it to a table).');

it('removes the files and rows of the exports when the customer is anonymized (152-FZ)', function (): void {
    Storage::disk('local')->put('exports/customers/'.$this->customer->uuid.'/a.pdf', 'personal data');
    $export = cxExportWithFile($this->customer->id, 'exports/customers/'.$this->customer->uuid.'/a.pdf', now()->addDay());
    $foreign = cxExportWithFile(makeCustomer()->id, 'exports/customers/other/b.pdf', now()->addDay());

    app(CustomerAnonymizer::class)->anonymize($this->customer);

    expect(Export::query()->find($export->id))->toBeNull()
        ->and(Storage::disk('local')->exists('exports/customers/'.$this->customer->uuid.'/a.pdf'))->toBeFalse()
        ->and(Export::query()->find($foreign->id))->not->toBeNull();
});
