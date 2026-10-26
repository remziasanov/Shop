<?php

declare(strict_types=1);

use App\Enums\PriceChangeSource;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function bulkPriceAction(): TestAction
{
    return TestAction::make('change_price')->table()->bulk();
}

describe('bulk price change', function (): void {
    it('lets an administrator change base prices of the selected products only', function (): void {
        $admin = User::factory()->admin()->create();
        actingAs($admin);
        $a = Product::factory()->create(['base_price' => '1000.00']);
        $b = Product::factory()->create(['base_price' => '500.00']);
        $other = Product::factory()->create(['base_price' => '700.00']);

        Livewire::test(ListProducts::class)
            ->assertActionVisible(bulkPriceAction())
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(bulkPriceAction(), ['operation' => 'increase_percent', 'value' => '10'])
            ->assertHasNoActionErrors();

        expect($a->fresh()->base_price)->toBe('1100.00')
            ->and($a->fresh()->final_price)->toBe('1100.00')
            ->and($b->fresh()->base_price)->toBe('550.00')
            ->and($other->fresh()->base_price)->toBe('700.00');

        $row = PriceHistory::query()->where('product_id', $a->id)->sole();

        expect($row->source)->toBe(PriceChangeSource::Bulk)
            ->and($row->user_id)->toBe($admin->id);
    });

    it('supports the rubles operations and rejects invalid values', function (): void {
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create(['base_price' => '1000.00']);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(bulkPriceAction(), ['operation' => 'decrease_fixed', 'value' => '99,90'])
            ->assertHasNoActionErrors();

        expect($product->fresh()->base_price)->toBe('900.10');

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$product->id])
            ->callAction(bulkPriceAction(), ['operation' => 'decrease_fixed', 'value' => '0'])
            ->assertHasActionErrors(['value']);

        expect($product->fresh()->base_price)->toBe('900.10');
    });

    it('is hidden from managers and cannot be executed by them', function (): void {
        actingAs(User::factory()->manager()->create());
        $product = Product::factory()->create(['base_price' => '1000.00']);

        Livewire::test(ListProducts::class)
            ->assertActionHidden(bulkPriceAction());

        expect(Gate::allows('changePrices', Product::class))->toBeFalse()
            ->and($product->fresh()->base_price)->toBe('1000.00')
            ->and(PriceHistory::query()->count())->toBe(0);
    });
});

describe('product form price block', function (): void {
    it('shows the explanation of rules that apply, including rules for this very product', function (): void {
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create(['base_price' => '1000.00']);
        $other = Product::factory()->create(['base_price' => '1000.00']);
        $rule = PriceRule::factory()->discountPercent(10)->forProducts([$product])->create(['name' => 'Акция для кресла']);
        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertOk()
            ->assertSee('Итог: '.Money::format(90_000))
            ->assertSee('База '.Money::format(100_000))
            ->assertSee('Акция “Акция для кресла −10'.Money::CURRENCY_SEPARATOR.'%” → '.Money::format(90_000));

        Livewire::test(EditProduct::class, ['record' => $other->getRouteKey()])
            ->assertDontSee('Акция для кресла')
            ->assertSee('Применённых правил нет');
    });

    it('recalculates the explanation live from the form state', function (): void {
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create(['base_price' => '1000.00', 'brand' => 'Север']);
        PriceRule::factory()->markupPercent(20)->forBrands(['Север'])->create(['name' => 'Север плюс']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['base_price' => '2000.00'])
            ->assertSee('Итог: '.Money::format(240_000))
            ->assertSee('Наценка “Север плюс +20'.Money::CURRENCY_SEPARATOR.'%” → '.Money::format(240_000))
            ->fillForm(['brand' => 'Другой'])
            ->assertSee('Итог: '.Money::format(200_000));
    });

    it('lists the last price history rows of the product, read-only', function (): void {
        actingAs(User::factory()->manager()->create());
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        PriceHistory::factory()->count(12)->create(['product_id' => $product->id, 'field' => 'cost_price', 'old_value' => '1.00', 'new_value' => '2.00']);
        PriceHistory::factory()->create(['product_id' => $product->id, 'field' => 'base_price', 'old_value' => '10.00', 'new_value' => '20.00', 'created_at' => now()->addMinute()]);
        PriceHistory::factory()->create(['product_id' => $other->id, 'field' => 'fixed_price', 'old_value' => '5.00', 'new_value' => '6.00']);

        $html = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('История цен')
            ->assertSee('Базовая цена')
            ->assertSee(Money::format(100).' → '.Money::format(200))
            ->assertDontSee(Money::format(500).' → '.Money::format(600))
            ->html();

        // 10 rows: the newest base price change plus 9 of the 12 cost price changes.
        expect(substr_count($html, Money::format(100).' → '.Money::format(200)))->toBe(9);
    });
});
