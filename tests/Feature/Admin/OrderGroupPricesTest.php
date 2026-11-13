<?php

declare(strict_types=1);

use App\Enums\PriceSource;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create());
    $this->order = Order::factory()->order()->create(['customer_group_id' => 7, 'customer_group_name' => 'Опт']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function groupLine(Order $order, array $attributes): OrderItem
{
    return OrderItem::factory()->forProduct(Product::factory()->create(), 1)->create(array_merge(['order_id' => $order->id], $attributes));
}

describe('price source of the order lines (GP-12)', function (): void {
    it('describes every source with the retail price when it differs', function (): void {
        $tier = groupLine($this->order, ['price' => '900.00', 'price_source' => PriceSource::Tier, 'tier_min_qty' => 10, 'retail_price' => '1200.00']);
        $exact = groupLine($this->order, ['price' => '1000.00', 'price_source' => PriceSource::GroupPrice, 'tier_min_qty' => null, 'retail_price' => '1200.00']);
        $discount = groupLine($this->order, ['price' => '1080.00', 'price_source' => PriceSource::GroupDiscount, 'tier_min_qty' => null, 'retail_price' => '1200.00']);
        $retail = groupLine($this->order, ['price' => '1200.00', 'price_source' => PriceSource::Retail, 'tier_min_qty' => null, 'retail_price' => '1200.00']);
        $equal = groupLine($this->order, ['price' => '1200.00', 'price_source' => PriceSource::GroupPrice, 'tier_min_qty' => null, 'retail_price' => '1200.00']);
        $old = groupLine($this->order, ['price' => '1200.00', 'price_source' => null, 'tier_min_qty' => null, 'retail_price' => null]);

        $retailText = Money::format(120_000);

        expect(OrderInfolist::priceSourceLine($tier))->toBe('Ступень от 10 шт, розница '.$retailText)
            ->and(OrderInfolist::priceSourceLine($exact))->toBe('Точная цена группы, розница '.$retailText)
            ->and(OrderInfolist::priceSourceLine($discount))->toBe('Скидка группы, розница '.$retailText)
            ->and(OrderInfolist::priceSourceLine($retail))->toBe('Розничная цена')
            ->and(OrderInfolist::priceSourceLine($equal))->toBe('Точная цена группы')
            ->and(OrderInfolist::priceSourceLine($old))->toBeNull();
    });

    it('shows the source and the group snapshot on the order card', function (): void {
        groupLine($this->order, ['name' => 'Стол', 'price' => '900.00', 'price_source' => PriceSource::Tier, 'tier_min_qty' => 10, 'retail_price' => '1200.00']);

        Livewire::test(ViewOrder::class, ['record' => $this->order->getRouteKey()])
            ->assertOk()
            ->assertSee('Группа покупателей')
            ->assertSee('Опт')
            ->assertSee('Ступень от 10 шт')
            ->assertSee('розница');
    });

    it('prints the source and the group', function (): void {
        groupLine($this->order, ['name' => 'Стол', 'price' => '900.00', 'price_source' => PriceSource::Tier, 'tier_min_qty' => 10, 'retail_price' => '1200.00']);

        $this->get(route('admin.orders.print', $this->order))
            ->assertOk()
            ->assertSee('Группа покупателей')
            ->assertSee('Опт')
            ->assertSee('Ступень от 10 шт');
    });

    it('shows no group line for an order without a group snapshot', function (): void {
        $order = Order::factory()->order()->create(['customer_group_id' => null, 'customer_group_name' => null]);
        groupLine($order, ['price' => '100.00']);

        $this->get(route('admin.orders.print', $order))->assertOk()->assertDontSee('Группа покупателей');
    });
});
