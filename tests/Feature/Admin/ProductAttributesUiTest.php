<?php

declare(strict_types=1);

use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Catalog\ProductAttributeEditor;
use App\Settings\CatalogSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function attributeProductForm(Category $category, array $overrides = []): array
{
    return array_merge(['name' => 'Дрель', 'sku' => 'ATT-1', 'category_id' => $category->id, 'base_price' => '1000', 'is_active' => true], $overrides);
}

describe('typed editor in the product form (FL-03)', function (): void {
    beforeEach(function (): void {
        attrMigrated();
        $this->color = Attribute::factory()->create(['name' => 'Цвет', 'sort_order' => 10]);
        $this->power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность', 'sort_order' => 20]);
        $this->wet = Attribute::factory()->boolean()->create(['name' => 'Влагозащита', 'sort_order' => 30]);
    });

    it('saves list, number and boolean rows, creates new dictionary values and refreshes the JSON', function (): void {
        actingAs(User::factory()->admin()->create());
        $category = Category::factory()->create();
        attrService()->createValue($this->color, 'Красный');

        Livewire::test(CreateProduct::class)
            ->fillForm(attributeProductForm($category, ['typed_specs' => [
                ['attribute_id' => $this->wet->id, 'boolean' => true],
                ['attribute_id' => $this->color->id, 'list' => ['красный', 'Новый оттенок']],
                ['attribute_id' => $this->power->id, 'number' => '900,5'],
            ]]))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::where('sku', 'ATT-1')->firstOrFail();

        expect(AttributeValue::query()->where('attribute_id', $this->color->id)->pluck('value')->all())->toBe(['Красный', 'Новый оттенок'])
            ->and(ProductAttributeValue::query()->where('product_id', $product->id)->count())->toBe(4)
            // Dictionary order, not the order of the rows in the form.
            ->and($product->specs())->toBe([
                ['name' => 'Цвет', 'value' => 'Красный, Новый оттенок'],
                ['name' => 'Мощность', 'value' => '900.5 Вт'],
                ['name' => 'Влагозащита', 'value' => 'да'],
            ]);
    });

    it('loads the rows of a product and saves changes, including the removal of a row', function (): void {
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create(['attributes' => null]);
        attrService()->setProductValues($product, [
            ['attribute_id' => $this->color->id, 'values' => ['Красный', 'Синий']],
            ['attribute_id' => $this->power->id, 'values' => '900'],
        ]);

        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $rows = array_values($component->get('data.typed_specs'));

        expect($rows)->toHaveCount(2)
            ->and((int) $rows[0]['attribute_id'])->toBe($this->color->id)
            ->and($rows[0]['list'])->toBe(['Красный', 'Синий'])
            ->and((int) $rows[1]['attribute_id'])->toBe($this->power->id)
            ->and($rows[1]['number'])->toBe('900');

        $component
            ->fillForm(['typed_specs' => [['attribute_id' => $this->power->id, 'number' => '1200']]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->fresh()->specs())->toBe([['name' => 'Мощность', 'value' => '1200 Вт']])
            ->and(ProductAttributeValue::query()->where('product_id', $product->id)->where('attribute_id', $this->color->id)->exists())->toBeFalse();
    });

    it('keeps the characteristics when the product is saved without touching them', function (): void {
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create();
        attrService()->setProductValues($product, [['attribute_id' => $this->color->id, 'values' => ['Красный']]]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'Новое имя'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->fresh()->name)->toBe('Новое имя')
            ->and($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный']]);
    });

    it('refuses a text in a number field and saves nothing', function (): void {
        actingAs(User::factory()->admin()->create());
        $category = Category::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm(attributeProductForm($category, ['typed_specs' => [['attribute_id' => $this->power->id, 'number' => 'много']]]))
            ->call('create')
            ->assertNotified(__('attributes.errors.not_a_number', ['name' => 'Мощность', 'value' => 'много']));

        expect(Product::where('sku', 'ATT-1')->exists())->toBeFalse();
    });

    it('does not allow the same characteristic twice', function (): void {
        actingAs(User::factory()->admin()->create());
        $category = Category::factory()->create();

        Livewire::test(CreateProduct::class)
            ->fillForm(attributeProductForm($category, ['typed_specs' => [
                ['attribute_id' => $this->color->id, 'list' => ['Красный']],
                ['attribute_id' => $this->color->id, 'list' => ['Синий']],
            ]]))
            ->call('create')
            ->assertHasFormErrors();

        expect(Product::where('sku', 'ATT-1')->exists())->toBeFalse();
    });

    it('offers the attribute list for the dictionary search', function (): void {
        actingAs(User::factory()->admin()->create());
        $editor = app(ProductAttributeEditor::class);

        expect($editor->attributeOptions())->toBe([$this->color->id => 'Цвет', $this->power->id => 'Мощность, Вт', $this->wet->id => 'Влагозащита'])
            ->and($editor->attributeOptions('ощн'))->toBe([$this->power->id => 'Мощность, Вт']);
    });
});

describe('legacy JSON before the migration (FL-04)', function (): void {
    beforeEach(function (): void {
        $settings = app(CatalogSettings::class);
        $settings->attributes_migrated_at = null;
        $settings->save();
    });

    it('shows the v1 editor with a notice for a product that only has JSON and keeps the data on save', function (): void {
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Белый'], ['name' => 'Вес', 'value' => '5 кг']]]);
        Attribute::factory()->create(['name' => 'Цвет']);

        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee(__('admin_attributes.product.legacy_notice'));

        expect(array_values($component->get('data.specs')))->toHaveCount(2);

        // Saving another field must not touch the JSON, and no rows are written.
        $component->fillForm(['name' => 'Новое имя'])->call('save')->assertHasNoFormErrors();

        expect($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Белый'], ['name' => 'Вес', 'value' => '5 кг']])
            ->and(ProductAttributeValue::query()->where('product_id', $product->id)->exists())->toBeFalse();

        $component->fillForm(['specs' => [['name' => 'Цвет', 'value' => 'Чёрный']]])->call('save')->assertHasNoFormErrors();

        expect($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Чёрный']])
            ->and(ProductAttributeValue::query()->count())->toBe(0);
    });

    it('refuses to save a form opened in the legacy mode once the migration finished or the product got rows', function (): void {
        actingAs(User::factory()->admin()->create());
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $product = Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Белый']]]);

        // The product got rows after the form was opened.
        $component = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])->assertSee(__('admin_attributes.product.legacy_notice'));
        attrService()->setProductValues($product, [['attribute_id' => $color->id, 'values' => ['Красный']]]);

        $component->fillForm(['name' => 'Другое имя'])->call('save')
            ->assertNotified(__('attributes.errors.legacy_editor_stale'));

        // Neither the rows nor the product are touched (the hidden typed editor would have saved an empty set).
        expect($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный']])
            ->and(ProductAttributeValue::query()->where('product_id', $product->id)->count())->toBe(1)
            ->and($product->fresh()->name)->not->toBe('Другое имя');

        // The migration finished after the form was opened.
        $other = Product::factory()->create(['attributes' => [['name' => 'Вес', 'value' => '5 кг']]]);
        $component = Livewire::test(EditProduct::class, ['record' => $other->getRouteKey()]);
        attrMigrated();

        $component->fillForm(['name' => 'Другое имя'])->call('save')
            ->assertNotified(__('attributes.errors.legacy_editor_stale'));

        expect($other->fresh()->specs())->toBe([['name' => 'Вес', 'value' => '5 кг']])
            ->and($other->fresh()->name)->not->toBe('Другое имя');
    });

    it('uses the typed editor for a new product and for a product that already has rows', function (): void {
        actingAs(User::factory()->admin()->create());
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $product = Product::factory()->create();
        attrService()->setProductValues($product, [['attribute_id' => $color->id, 'values' => ['Красный']]]);

        Livewire::test(CreateProduct::class)->assertDontSee(__('admin_attributes.product.legacy_notice'));
        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertDontSee(__('admin_attributes.product.legacy_notice'));
    });
});

describe('bulk actions (FL-18)', function (): void {
    beforeEach(function (): void {
        attrMigrated();
        $this->color = Attribute::factory()->create(['name' => 'Цвет']);
        $this->power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $this->products = Product::factory()->count(3)->create(['attributes' => null]);
    });

    it('sets a list value on the selected products only', function (): void {
        actingAs(User::factory()->manager()->create());

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$this->products[0]->id, $this->products[1]->id])
            ->callAction(TestAction::make('set_attribute')->table()->bulk(), ['attribute_id' => $this->color->id, 'list' => ['Красный', 'Синий']]);

        expect($this->products[0]->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный, Синий']])
            ->and($this->products[1]->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный, Синий']])
            ->and($this->products[2]->fresh()->specs())->toBe([]);
    });

    it('sets a number and replaces the previous value', function (): void {
        actingAs(User::factory()->admin()->create());
        attrService()->setProductValues($this->products[0], [['attribute_id' => $this->power->id, 'values' => '100']]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$this->products[0]->id, $this->products[2]->id])
            ->callAction(TestAction::make('set_attribute')->table()->bulk(), ['attribute_id' => $this->power->id, 'number' => '750']);

        expect($this->products[0]->fresh()->specs())->toBe([['name' => 'Мощность', 'value' => '750 Вт']])
            ->and($this->products[2]->fresh()->specs())->toBe([['name' => 'Мощность', 'value' => '750 Вт']]);
    });

    it('does not remove anything when the value is empty and reports an invalid number', function (): void {
        actingAs(User::factory()->admin()->create());
        attrService()->setProductValues($this->products[0], [['attribute_id' => $this->power->id, 'values' => '100']]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$this->products[0]->id])
            ->callAction(TestAction::make('set_attribute')->table()->bulk(), ['attribute_id' => $this->power->id, 'number' => ''])
            ->assertNotified(__('admin_attributes.bulk.value_required', ['name' => 'Мощность']));

        expect($this->products[0]->fresh()->specs())->toBe([['name' => 'Мощность', 'value' => '100 Вт']]);

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$this->products[0]->id])
            ->callAction(TestAction::make('set_attribute')->table()->bulk(), ['attribute_id' => $this->power->id, 'number' => 'abc'])
            ->assertNotified(__('attributes.errors.not_a_number', ['name' => 'Мощность', 'value' => 'abc']));
    });

    it('removes a characteristic from the selected products', function (): void {
        actingAs(User::factory()->admin()->create());

        foreach ($this->products as $product) {
            attrService()->setProductValues($product, [['attribute_id' => $this->color->id, 'values' => ['Красный']], ['attribute_id' => $this->power->id, 'values' => '5']]);
        }

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$this->products[0]->id, $this->products[1]->id])
            ->callAction(TestAction::make('remove_attribute')->table()->bulk(), ['attribute_id' => $this->color->id]);

        expect($this->products[0]->fresh()->specs())->toBe([['name' => 'Мощность', 'value' => '5 Вт']])
            ->and($this->products[1]->fresh()->specs())->toBe([['name' => 'Мощность', 'value' => '5 Вт']])
            ->and($this->products[2]->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный'], ['name' => 'Мощность', 'value' => '5 Вт']]);
    });

    it('needs products.bulk', function (): void {
        actingAs(staffWith(['dashboard.view', 'products.view', 'products.update']));

        Livewire::test(ListProducts::class)
            ->assertActionHidden(TestAction::make('set_attribute')->table()->bulk())
            ->assertActionHidden(TestAction::make('remove_attribute')->table()->bulk());

        actingAs(staffWith(['dashboard.view', 'products.view', 'products.bulk']));

        Livewire::test(ListProducts::class)
            ->assertActionVisible(TestAction::make('set_attribute')->table()->bulk())
            ->assertActionVisible(TestAction::make('remove_attribute')->table()->bulk());
    });
});

describe('bulk actions before the JSON migration is finished (FL-04)', function (): void {
    it('are disabled while some product only has JSON characteristics and do not touch the JSON', function (): void {
        actingAs(User::factory()->admin()->create());
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $legacy = Product::factory()->create(['attributes' => [['name' => 'Вес', 'value' => '2 кг']]]);

        Livewire::test(ListProducts::class)
            ->assertActionDisabled(TestAction::make('set_attribute')->table()->bulk())
            ->assertActionDisabled(TestAction::make('remove_attribute')->table()->bulk());

        expect($legacy->fresh()->specs())->toBe([['name' => 'Вес', 'value' => '2 кг']]);
    });
});
