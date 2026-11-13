<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\ProductImagesRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Variants\VariantService;
use App\Settings\CatalogSettings;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create());
    $this->color = optionWith('Цвет', ['Красный', 'Синий'], color: true);
    $this->size = optionWith('Размер', ['S', 'M', 'L']);
});

function variantsRelationTable(Product $product)
{
    return Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);
}

describe('switching variants on and off (VR-02, VR-25)', function (): void {
    it('enables variants with 1 to 3 axes and refuses more', function (): void {
        $product = sellable(['base_price' => '1000.00']);
        $extra = [optionWith('A', ['1']), optionWith('B', ['1'])];

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('enable_variants', ['axes' => [$this->color->id, $this->size->id, $extra[0]->id, $extra[1]->id]])
            ->assertHasActionErrors(['axes']);

        expect($product->refresh()->has_variants)->toBeFalse();

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('enable_variants', ['axes' => [$this->size->id, $this->color->id], 'from_current' => false])
            ->assertHasNoActionErrors();

        expect($product->refresh()->has_variants)->toBeTrue()
            ->and(app(VariantService::class)->axes($product)->pluck('name')->all())->toBe(['Размер', 'Цвет'])
            ->and($product->variants()->count())->toBe(0);
    });

    it('moves the current price and stock into the first variant on request (VR-25)', function (): void {
        $product = sellable(['base_price' => '1500.00', 'stock_qty' => 7]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('enable_variants', [
                'axes' => [$this->size->id],
                'from_current' => true,
                'value_1' => $this->size->values[1]->id,
            ])
            ->assertHasNoActionErrors();

        $variant = $product->variants()->firstOrFail();

        expect($variant->base_price)->toBe('1500.00')
            ->and($variant->stock_qty)->toBe(7)
            ->and($variant->sku)->toStartWith($product->sku)
            ->and($product->refresh()->stock_qty)->toBe(7);
    });

    it('refuses to switch with the numbers of the unfinished requests (VR-25)', function (): void {
        $product = sellable();
        $order = Order::factory()->order()->create(['status' => OrderStatus::Confirmed]);
        OrderItem::factory()->forProduct($product, 1)->create(['order_id' => $order->id]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction('enable_variants', ['axes' => [$this->size->id]])
            ->assertNotified(__('variants.errors.unfinished_requests', ['numbers' => $order->number]));

        expect($product->refresh()->has_variants)->toBeFalse();

        $card = variantCard([], $this->size);
        $reservation = Order::factory()->reservation()->create(['status' => OrderStatus::Reserved]);
        OrderItem::factory()->forProduct($card, 1)->create(['order_id' => $reservation->id]);

        Livewire::test(EditProduct::class, ['record' => $card->getRouteKey()])
            ->callAction('disable_variants')
            ->assertNotified(__('variants.errors.unfinished_requests', ['numbers' => $reservation->number]));

        expect($card->refresh()->has_variants)->toBeTrue();
    });

    it('disables variants: they are deleted and the card keeps the stock sum', function (): void {
        $card = variantCard([], $this->size);
        variantOf($card, [$this->size->values[0]], ['stock_qty' => 3]);
        variantOf($card, [$this->size->values[1]], ['stock_qty' => 4]);

        Livewire::test(EditProduct::class, ['record' => $card->getRouteKey()])
            ->callAction('disable_variants')
            ->assertHasNoActionErrors();

        $card->refresh();
        expect($card->has_variants)->toBeFalse()
            ->and(ProductVariant::withTrashed()->where('product_id', $card->id)->count())->toBe(0)
            ->and($card->stock_qty)->toBe(7);
    });

    it('changes the axes of a card without variants and refuses it once variants exist', function (): void {
        $card = variantCard([], $this->size);

        Livewire::test(EditProduct::class, ['record' => $card->getRouteKey()])
            ->callAction('edit_axes', ['axes' => [$this->color->id]]);

        expect(app(VariantService::class)->axes($card)->pluck('name')->all())->toBe(['Цвет']);

        variantOf($card, [$this->color->values[0]]);

        Livewire::test(EditProduct::class, ['record' => $card->getRouteKey()])
            ->callAction('edit_axes', ['axes' => [$this->size->id]])
            ->assertNotified(__('variants.errors.axes_locked'));

        expect(app(VariantService::class)->axes($card)->pluck('name')->all())->toBe(['Цвет']);
    });

    it('hides the variant actions from a user who may not update products', function (): void {
        $card = variantCard([], $this->size);
        $variant = variantOf($card, [$this->size->values[0]]);
        actingAs(staffWith(['dashboard.view', 'products.view']));

        variantsRelationTable($card)
            ->assertActionHidden(TestAction::make('generate')->table())
            ->assertActionHidden(TestAction::make('add')->table())
            ->assertActionHidden(TestAction::make('delete_variant')->table($variant))
            ->assertActionHidden(TestAction::make('duplicate')->table($variant));
    });
});

describe('the «Модификации» tab (VR-24)', function (): void {
    it('is visible only while variants are on', function (): void {
        $plain = sellable();
        $card = variantCard([], $this->size);

        expect(VariantsRelationManager::canViewForRecord($plain, EditProduct::class))->toBeFalse()
            ->and(VariantsRelationManager::canViewForRecord($card, EditProduct::class))->toBeTrue();
    });

    it('makes the stock field a read-only sum on the stock tab', function (): void {
        $card = variantCard([], $this->size);
        variantOf($card, [$this->size->values[0]], ['stock_qty' => 3]);
        variantOf($card, [$this->size->values[1]], ['stock_qty' => 4]);

        $component = Livewire::test(EditProduct::class, ['record' => $card->getRouteKey()])
            ->assertSee(__('admin_variants.stock.sum_label'));

        expect($component->get('data.stock_qty'))->toBe(7);

        // A forged value does not reach StockService: the aggregate stays what the variants make it.
        $component->set('data.stock_qty', 99)->call('save');

        expect($card->refresh()->stock_qty)->toBe(7);
    });

    it('lists the variants with their labels', function (): void {
        $card = variantCard([], $this->color, $this->size);
        $variant = variantOf($card, [$this->color->values[0], $this->size->values[1]]);

        variantsRelationTable($card)
            ->assertCanSeeTableRecords([$variant])
            ->assertTableColumnStateSet('label', 'Цвет: Красный, Размер: M', $variant);
    });

    it('edits sku, barcode, price, stock, activity and order inline through VariantService', function (): void {
        $card = variantCard([], $this->size);
        $variant = variantOf($card, [$this->size->values[0]], ['stock_qty' => 1]);

        variantsRelationTable($card)
            ->call('updateTableColumnState', 'sku', $variant->getKey(), 'NEW-SKU')
            ->call('updateTableColumnState', 'barcode', $variant->getKey(), '4600000000012')
            ->call('updateTableColumnState', 'base_price', $variant->getKey(), '1 250,50')
            ->call('updateTableColumnState', 'stock_qty', $variant->getKey(), '9')
            ->call('updateTableColumnState', 'sort_order', $variant->getKey(), '5')
            ->call('updateTableColumnState', 'is_active', $variant->getKey(), false);

        $variant->refresh();
        expect($variant->sku)->toBe('NEW-SKU')
            ->and($variant->barcode)->toBe('4600000000012')
            ->and($variant->base_price)->toBe('1250.50')
            ->and($variant->stock_qty)->toBe(9)
            ->and($variant->sort_order)->toBe(5)
            ->and($variant->is_active)->toBeFalse()
            ->and($card->refresh()->stock_qty)->toBeNull();
    });

    it('refuses an article that another product or variant uses', function (): void {
        $other = sellable(['sku' => 'TAKEN-1']);
        $card = variantCard([], $this->size);
        $variant = variantOf($card, [$this->size->values[0]], ['sku' => 'OWN-1']);

        variantsRelationTable($card)->call('updateTableColumnState', 'sku', $variant->getKey(), 'TAKEN-1');

        expect($variant->refresh()->sku)->toBe('OWN-1');
    });

    it('does not let a user without products.update edit inline', function (): void {
        $card = variantCard([], $this->size);
        $variant = variantOf($card, [$this->size->values[0]], ['sku' => 'OWN-1']);
        actingAs(staffWith(['dashboard.view', 'products.view']));

        variantsRelationTable($card)->call('updateTableColumnState', 'sku', $variant->getKey(), 'HACK');

        expect($variant->refresh()->sku)->toBe('OWN-1');
    });

    it('edits the own prices of a variant in a modal and clears them', function (): void {
        $card = variantCard([], $this->size);
        $variant = variantOf($card, [$this->size->values[0]]);

        variantsRelationTable($card)
            ->callAction(TestAction::make('prices')->table($variant), ['base_price' => '900', 'cost_price' => '500', 'compare_at_price' => '1200', 'fixed_price' => ''])
            ->assertHasNoFormErrors();

        $variant->refresh();
        expect($variant->base_price)->toBe('900.00')->and($variant->cost_price)->toBe('500.00')->and($variant->compare_at_price)->toBe('1200.00')->and($variant->fixed_price)->toBeNull();

        variantsRelationTable($card)->callAction(TestAction::make('prices')->table($variant), ['base_price' => '', 'cost_price' => '', 'compare_at_price' => '', 'fixed_price' => '']);

        expect($variant->refresh()->base_price)->toBeNull();
    });

    it('duplicates a variant under other values with a «-copy» article and zero stock', function (): void {
        $card = variantCard([], $this->size);
        $variant = variantOf($card, [$this->size->values[0]], ['sku' => 'TEE-S', 'stock_qty' => 5]);

        variantsRelationTable($card)
            ->callAction(TestAction::make('duplicate')->table($variant), ['axis_'.$this->size->id => $this->size->values[0]->id])
            ->assertNotified(__('variants.errors.combo_exists'));

        variantsRelationTable($card)
            ->callAction(TestAction::make('duplicate')->table($variant), ['axis_'.$this->size->id => $this->size->values[1]->id]);

        $copy = ProductVariant::query()->where('sku', 'TEE-S-copy')->firstOrFail();
        expect($copy->stock_qty)->toBe(0)->and($copy->product_id)->toBe($card->id);
    });

    it('deletes a variant', function (): void {
        $card = variantCard([], $this->size);
        $variant = variantOf($card, [$this->size->values[0]]);

        variantsRelationTable($card)->callAction(TestAction::make('delete_variant')->table($variant));

        expect(ProductVariant::query()->find($variant->id))->toBeNull()
            ->and(ProductVariant::withTrashed()->find($variant->id))->not->toBeNull();
    });

    it('adds a single variant', function (): void {
        $card = variantCard([], $this->size);

        variantsRelationTable($card)->callAction(TestAction::make('add')->table(), ['axis_'.$this->size->id => $this->size->values[2]->id, 'sku' => 'TEE-L', 'stock_qty' => 3]);

        expect(ProductVariant::query()->where('sku', 'TEE-L')->firstOrFail()->stock_qty)->toBe(3);
    });

    it('runs the bulk actions: stock, price, activate and hide', function (): void {
        $card = variantCard([], $this->size);
        $variants = collect($this->size->values)->map(fn ($value): ProductVariant => variantOf($card, [$value], ['stock_qty' => 1]));

        variantsRelationTable($card)
            ->selectTableRecords($variants->pluck('id')->all())
            ->callAction(TestAction::make('set_stock')->table()->bulk(), ['stock_qty' => 12])
            ->assertHasNoFormErrors();
        expect($variants->map(fn (ProductVariant $v): ?int => $v->refresh()->stock_qty)->unique()->all())->toBe([12]);

        variantsRelationTable($card)->selectTableRecords($variants->pluck('id')->all())->callAction(TestAction::make('set_price')->table()->bulk(), ['field' => 'base_price', 'price' => '777']);
        expect($variants->map(fn (ProductVariant $v): ?string => $v->refresh()->base_price)->unique()->all())->toBe(['777.00']);

        variantsRelationTable($card)->selectTableRecords($variants->pluck('id')->all())->callAction(TestAction::make('set_price')->table()->bulk(), ['field' => 'base_price', 'price' => '']);
        expect($variants->map(fn (ProductVariant $v): ?string => $v->refresh()->base_price)->unique()->all())->toBe([null]);

        variantsRelationTable($card)->selectTableRecords([$variants[0]->id, $variants[1]->id])->callAction(TestAction::make('hide')->table()->bulk());
        expect($variants[0]->refresh()->is_active)->toBeFalse()->and($variants[2]->refresh()->is_active)->toBeTrue()
            ->and($card->refresh()->stock_qty)->toBe(12);

        variantsRelationTable($card)->selectTableRecords([$variants[0]->id])->callAction(TestAction::make('activate')->table()->bulk());
        expect($variants[0]->refresh()->is_active)->toBeTrue();
    });
});

describe('combination generator (VR-05)', function (): void {
    it('previews «будет создано N, уже есть M» and creates the missing combinations with the edited SKUs', function (): void {
        $card = variantCard(['sku' => 'TEE'], $this->color, $this->size);
        variantOf($card, [$this->color->values[0], $this->size->values[0]], ['sku' => 'TEE-krasnyj-s']);
        $selection = [
            'axis_'.$this->color->id => [$this->color->values[0]->id, $this->color->values[1]->id],
            'axis_'.$this->size->id => [$this->size->values[0]->id, $this->size->values[1]->id],
        ];

        $component = variantsRelationTable($card)->mountAction(TestAction::make('generate')->table())->fillForm($selection);

        $rows = $component->get('mountedActions.0.data.rows');
        expect($rows)->toHaveCount(3);

        $edited = array_values($rows);
        $edited[0]['sku'] = 'MY-FIRST';
        $component->fillForm(['rows' => $edited])->callMountedAction()->assertHasNoActionErrors();

        expect(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(4)
            ->and(ProductVariant::query()->where('sku', 'MY-FIRST')->exists())->toBeTrue();
    });

    it('creates from the selection when the preview list was not sent', function (): void {
        $card = variantCard(['sku' => 'TEE'], $this->size);

        variantsRelationTable($card)->callAction(TestAction::make('generate')->table(), [
            'axis_'.$this->size->id => [$this->size->values[0]->id, $this->size->values[1]->id, $this->size->values[2]->id],
            'template' => '{sku}-{v1}',
        ]);

        expect(ProductVariant::query()->where('product_id', $card->id)->orderBy('id')->pluck('sku')->all())->toBe(['TEE-s', 'TEE-m', 'TEE-l']);
    });

    it('refuses to exceed the limit before creating anything (VR-02)', function (): void {
        app(CatalogSettings::class)->fill(['max_variants_per_product' => 2])->save();
        $card = variantCard(['sku' => 'TEE'], $this->size);

        variantsRelationTable($card)
            ->callAction(TestAction::make('generate')->table(), ['axis_'.$this->size->id => [$this->size->values[0]->id, $this->size->values[1]->id, $this->size->values[2]->id]])
            ->assertNotified(__('variants.errors.limit', ['max' => 2, 'total' => 3]));

        expect(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(0);
    });

    it('refuses an article that is already taken and creates nothing', function (): void {
        sellable(['sku' => 'TEE-s']);
        $card = variantCard(['sku' => 'TEE'], $this->size);

        variantsRelationTable($card)
            ->callAction(TestAction::make('generate')->table(), ['axis_'.$this->size->id => [$this->size->values[0]->id, $this->size->values[1]->id], 'template' => '{sku}-{v1}'])
            ->assertNotified(__('variants.errors.sku_taken', ['sku' => 'TEE-s']));

        expect(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(0);
    });
});

describe('photo colour (VR-11)', function (): void {
    function productImagesTable(Product $product)
    {
        return Livewire::test(ProductImagesRelationManager::class, ['ownerRecord' => $product, 'pageClass' => EditProduct::class]);
    }

    it('binds a photo to a value of the colour axis and makes it common again', function (): void {
        $card = variantCard([], $this->color, $this->size);
        $image = ProductImage::factory()->create(['product_id' => $card->id]);

        productImagesTable($card)->assertTableColumnVisible('option_value_id')
            ->call('updateTableColumnState', 'option_value_id', $image->getKey(), (string) $this->color->values[1]->id);

        expect($image->refresh()->option_value_id)->toBe($this->color->values[1]->id);

        productImagesTable($card)->call('updateTableColumnState', 'option_value_id', $image->getKey(), null);

        expect($image->refresh()->option_value_id)->toBeNull();
    });

    it('keeps the common photos ahead of the colour ones, so the main photo is a common one (VR-11)', function (): void {
        $card = variantCard([], $this->color);
        $first = ProductImage::factory()->create(['product_id' => $card->id, 'sort_order' => 0]);
        $second = ProductImage::factory()->create(['product_id' => $card->id, 'sort_order' => 1]);

        productImagesTable($card)->call('updateTableColumnState', 'option_value_id', $first->getKey(), (string) $this->color->values[0]->id);

        expect($card->images()->orderBy('sort_order')->pluck('id')->all())->toBe([$second->id, $first->id])
            ->and($card->mainImage()->value('id'))->toBe($second->id);
    });

    it('refuses a value of a non-colour option and hides the column without a colour axis', function (): void {
        $card = variantCard([], $this->color, $this->size);
        $image = ProductImage::factory()->create(['product_id' => $card->id]);

        productImagesTable($card)->call('updateTableColumnState', 'option_value_id', $image->getKey(), (string) $this->size->values[0]->id);

        expect($image->refresh()->option_value_id)->toBeNull();

        $sizeOnly = variantCard([], $this->size);
        productImagesTable($sizeOnly)->assertTableColumnHidden('option_value_id');
        productImagesTable(sellable())->assertTableColumnHidden('option_value_id');
    });
});

describe('products table (19.4)', function (): void {
    it('shows the «N модификаций» badge, the price range and the aggregate stock, and filters by variants', function (): void {
        $card = variantCard(['base_price' => '1000.00'], $this->size);
        variantOf($card, [$this->size->values[0]], ['base_price' => '1000.00', 'stock_qty' => 2]);
        variantOf($card, [$this->size->values[1]], ['base_price' => '1500.00', 'stock_qty' => 3]);
        $plain = sellable(['stock_qty' => 4]);

        $component = Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$card, $plain])
            ->assertTableColumnFormattedStateSet('variants_count', trans_choice('admin_variants.table.variants_badge', 2, ['count' => 2]), $card)
            ->assertTableColumnFormattedStateSet('final_price', Money::format(100_000).' – '.Money::format(150_000), $card)
            ->assertTableColumnStateSet('stock_qty', '5 / 5', $card);

        $component->filterTable('with_variants', true)
            ->assertCanSeeTableRecords([$card])
            ->assertCanNotSeeTableRecords([$plain]);
    });

    it('shows a single price when all variants cost the same', function (): void {
        $card = variantCard(['base_price' => '1000.00'], $this->size);
        variantOf($card, [$this->size->values[0]]);
        variantOf($card, [$this->size->values[1]]);

        Livewire::test(ListProducts::class)
            ->assertTableColumnFormattedStateSet('final_price', Money::format(100_000), $card);
    });
});
