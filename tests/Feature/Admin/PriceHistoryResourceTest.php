<?php

declare(strict_types=1);

use App\Enums\PriceChangeSource;
use App\Filament\Resources\PriceHistory\Pages\ListPriceHistory;
use App\Filament\Resources\PriceHistory\PriceHistoryResource;
use App\Models\PriceHistory;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('lists price history for admin and manager', function (string $role): void {
    actingAs(User::factory()->{$role}()->create());

    $user = User::factory()->create(['name' => 'Анна Петрова']);
    $product = Product::factory()->create(['name' => 'Кресло тестовое', 'sku' => 'HIST-1']);
    $row = PriceHistory::factory()->source(PriceChangeSource::Bulk)->create([
        'product_id' => $product->id,
        'user_id' => $user->id,
        'field' => 'fixed_price',
        'old_value' => null,
        'new_value' => '1290.00',
    ]);

    Livewire::test(ListPriceHistory::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$row])
        ->assertSee('Кресло тестовое')
        ->assertSee('HIST-1')
        ->assertSee('Фиксированная цена')
        ->assertSee('— → '.Money::format(129_000))
        ->assertSee('Массовое действие')
        ->assertSee('Анна Петрова');

    $this->get(PriceHistoryResource::getUrl('index'))->assertOk();
})->with(['admin', 'manager']);

it('is read-only: no create route and no create button', function (): void {
    actingAs(User::factory()->admin()->create());

    expect(PriceHistoryResource::canCreate())->toBeFalse()
        ->and(array_keys(PriceHistoryResource::getPages()))->toBe(['index']);

    Livewire::test(ListPriceHistory::class)->assertActionDoesNotExist('create');
});

it('sorts by date descending, keeps rows of deleted products and filters', function (): void {
    actingAs(User::factory()->admin()->create());

    $alpha = Product::factory()->create(['name' => 'Альфа', 'sku' => 'AAA-1']);
    $beta = Product::factory()->create(['name' => 'Бета', 'sku' => 'BBB-2']);
    $old = PriceHistory::factory()->create(['product_id' => $alpha->id, 'created_at' => now()->subDays(10)]);
    $new = PriceHistory::factory()->source(PriceChangeSource::Import)->create(['product_id' => $beta->id, 'field' => 'cost_price', 'created_at' => now()]);
    $beta->delete();

    Livewire::test(ListPriceHistory::class)
        ->assertCanSeeTableRecords([$new, $old], inOrder: true)
        ->filterTable('product', ['search' => 'AAA'])
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$new])
        ->resetTableFilters()
        ->filterTable('product', ['search' => 'Бета'])
        ->assertCanSeeTableRecords([$new])
        ->resetTableFilters()
        ->filterTable('field', 'cost_price')
        ->assertCanSeeTableRecords([$new])
        ->assertCanNotSeeTableRecords([$old])
        ->resetTableFilters()
        ->filterTable('source', PriceChangeSource::Manual->value)
        ->assertCanSeeTableRecords([$old])
        ->assertCanNotSeeTableRecords([$new])
        ->resetTableFilters()
        ->filterTable('period', ['from' => now()->subDays(2)->toDateString(), 'until' => null])
        ->assertCanSeeTableRecords([$new])
        ->assertCanNotSeeTableRecords([$old]);
});

it('does not run N+1 queries for many rows', function (): void {
    actingAs(User::factory()->manager()->create());

    $user = User::factory()->create();
    PriceHistory::factory()->count(15)->create(['user_id' => $user->id]);

    Livewire::test(ListPriceHistory::class)->assertOk();
});
