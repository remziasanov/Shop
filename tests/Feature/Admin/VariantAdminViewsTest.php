<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\ManageCatalogSettings;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Dashboard\DashboardStats;
use App\Settings\CatalogSettings;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create());
    Cache::flush();
});

describe('dashboard «нет в наличии» (AD-10, 19.6)', function (): void {
    it('counts a card with variants as sold out when no active variant is available', function (): void {
        $size = optionWith('Размер', ['S', 'M', 'L']);

        // Sold out: every variant is empty, one is hidden but has stock.
        $soldOut = variantCard([], $size);
        variantOf($soldOut, [$size->values[0]], ['stock_qty' => 0]);
        variantOf($soldOut, [$size->values[1]], ['stock_qty' => 5, 'is_active' => false]);

        // Available: one variant has free stock.
        $available = variantCard([], $size);
        variantOf($available, [$size->values[0]], ['stock_qty' => 0]);
        variantOf($available, [$size->values[1]], ['stock_qty' => 3]);

        // Available: an untracked variant is always on sale.
        $untracked = variantCard([], $size);
        variantOf($untracked, [$size->values[0]], ['stock_qty' => null]);

        // Sold out: free stock is used up by the reservation.
        $reserved = variantCard([], $size);
        variantOf($reserved, [$size->values[0]], ['stock_qty' => 2, 'reserved_qty' => 2]);

        // A card without any variant has nothing to sell.
        variantCard([], $size);

        // A plain product behaves as before.
        Product::factory()->create(['stock_qty' => 0, 'reserved_qty' => 0]);
        Product::factory()->create(['stock_qty' => 4]);

        expect(app(DashboardStats::class)->products()['out_of_stock'])->toBe(4)
            ->and(app(DashboardStats::class)->products()['active'])->toBe(7);
    });
});

describe('order lines (VR-12)', function (): void {
    function orderWithVariantLine(): Order
    {
        $size = optionWith('Размер', ['M']);
        $card = variantCard(['name' => 'Футболка'], $size);
        $variant = variantOf($card, [$size->values[0]], ['sku' => 'TEE-M', 'barcode' => '4600000000123']);
        $order = Order::factory()->order()->create();
        OrderItem::factory()->forVariant($variant, 2)->create(['order_id' => $order->id, 'variant_label' => 'Размер: M', 'barcode' => '4600000000123']);
        OrderItem::factory()->forProduct(Product::factory()->create(['sku' => 'PLAIN']), 1)->create(['order_id' => $order->id, 'variant_label' => null, 'barcode' => null]);

        return $order;
    }

    it('shows the variant label and barcode of a line in the request card', function (): void {
        $order = orderWithVariantLine();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('TEE-M')
            ->assertSee('Размер: M')
            ->assertSee(__('admin_variants.order.barcode', ['barcode' => '4600000000123']));
    });

    it('prints the variant label and barcode of a line', function (): void {
        $order = orderWithVariantLine();

        $this->get(route('admin.orders.print', $order))
            ->assertOk()
            ->assertSee('Размер: M')
            ->assertSee(__('admin_variants.order.barcode', ['barcode' => '4600000000123']))
            ->assertSee('TEE-M');
    });
});

describe('catalog settings (VR-02, VR-16)', function (): void {
    it('edits the limit of variants and the creation of options on import', function (): void {
        Livewire::test(ManageCatalogSettings::class)
            ->fillForm(['max_variants_per_product' => 40, 'create_options_on_import' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(CatalogSettings::class);

        expect($settings->max_variants_per_product)->toBe(40)->and($settings->create_options_on_import)->toBeFalse();

        Livewire::test(ManageCatalogSettings::class)
            ->fillForm(['max_variants_per_product' => 101])
            ->call('save')
            ->assertHasFormErrors(['max_variants_per_product']);
    });
});
