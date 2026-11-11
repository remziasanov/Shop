<?php

declare(strict_types=1);

use App\Enums\AttributeType;
use App\Filament\Resources\Attributes\AttributeResource;
use App\Filament\Resources\Attributes\Pages\CreateAttribute;
use App\Filament\Resources\Attributes\Pages\EditAttribute;
use App\Filament\Resources\Attributes\Pages\ListAttributes;
use App\Filament\Resources\Attributes\RelationManagers\ValuesRelationManager;
use App\Filament\Widgets\AttributeMigrationWidget;
use App\Jobs\MigrateAttributesFromJson;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Catalog\AttributeMigrationState;
use App\Settings\CatalogSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(fn () => Storage::fake('local'));

/**
 * @return array{0: Attribute, 1: AttributeValue, 2: AttributeValue}
 */
function listAttributeWithValues(): array
{
    $attribute = Attribute::factory()->create(['name' => 'Цвет']);
    $red = attrService()->createValue($attribute, 'Красный');
    $blue = attrService()->createValue($attribute, 'Синий');

    return [$attribute, $red, $blue];
}

describe('permissions (FL-17, 25.4)', function (): void {
    it('lets admin and manager open the pages', function (string $role): void {
        actingAs(User::factory()->{$role}()->create());
        $attribute = Attribute::factory()->create();

        $this->get(AttributeResource::getUrl('index'))->assertOk();
        $this->get(AttributeResource::getUrl('create'))->assertOk();
        $this->get(AttributeResource::getUrl('edit', ['record' => $attribute]))->assertOk();
    })->with(['admin', 'manager']);

    it('answers 403 without attributes.* permissions', function (): void {
        actingAs(staffWith(['dashboard.view']));
        $attribute = Attribute::factory()->create();

        $this->get(AttributeResource::getUrl('index'))->assertForbidden();
        $this->get(AttributeResource::getUrl('create'))->assertForbidden();
        $this->get(AttributeResource::getUrl('edit', ['record' => $attribute]))->assertForbidden();
    });

    it('lets a viewer see the list but not create or edit', function (): void {
        actingAs(staffWith(['dashboard.view', 'attributes.view']));
        $attribute = Attribute::factory()->create();

        $this->get(AttributeResource::getUrl('index'))->assertOk();
        $this->get(AttributeResource::getUrl('create'))->assertForbidden();
        $this->get(AttributeResource::getUrl('edit', ['record' => $attribute]))->assertForbidden();
    });

    it('hides the value editing actions from a viewer', function (): void {
        actingAs(staffWith(['dashboard.view', 'attributes.view', 'attributes.update']));
        [$attribute, $red] = listAttributeWithValues();

        Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $attribute, 'pageClass' => EditAttribute::class])
            ->assertTableActionVisible('merge', $red);

        actingAs(staffWith(['dashboard.view', 'attributes.view']));

        Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $attribute, 'pageClass' => EditAttribute::class])
            ->assertTableActionHidden('merge', $red);
    });
});

describe('list and form (FL-01, FL-17)', function (): void {
    it('shows name, type, unit, filter flag and the counts of values and products', function (): void {
        actingAs(User::factory()->admin()->create());
        [$color, $red] = listAttributeWithValues();
        $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $products = Product::factory()->count(2)->create();

        foreach ($products as $product) {
            attrService()->setProductValues($product, [['attribute_id' => $color->id, 'values' => ['Красный', 'Синий']]]);
        }

        $component = Livewire::test(ListAttributes::class)
            ->assertCanSeeTableRecords([$color, $power])
            ->assertSee('Мощность')
            ->assertSee('Вт');

        $records = $component->instance()->getTableRecords();
        $row = $records->firstWhere('id', $color->id);

        expect((int) $row->values_count)->toBe(2)
            ->and((int) $row->products_count)->toBe(2)
            ->and((int) $records->firstWhere('id', $power->id)->products_count)->toBe(0);
    });

    it('creates a number characteristic with a unit through the service', function (): void {
        actingAs(User::factory()->admin()->create());

        Livewire::test(CreateAttribute::class)
            ->fillForm(['name' => 'Мощность', 'type' => 'number', 'unit' => 'Вт', 'is_filterable' => true, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $attribute = Attribute::query()->where('name', 'Мощность')->firstOrFail();

        expect($attribute->type)->toBe(AttributeType::Number)
            ->and($attribute->unit)->toBe('Вт')
            ->and($attribute->slug)->not->toBe('')
            ->and($attribute->is_filterable)->toBeTrue();
    });

    it('validates the name', function (): void {
        actingAs(User::factory()->admin()->create());

        Livewire::test(CreateAttribute::class)
            ->fillForm(['name' => '', 'type' => 'list'])
            ->call('create')
            ->assertHasFormErrors(['name' => 'required']);
    });

    it('updates a characteristic and rebuilds the JSON cache of its products', function (): void {
        actingAs(User::factory()->admin()->create());
        $attribute = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $product = Product::factory()->create();
        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => '900']]);

        Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
            ->assertSchemaStateSet(['name' => 'Мощность', 'type' => 'number', 'unit' => 'Вт'])
            ->fillForm(['name' => 'Мощность двигателя'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($attribute->fresh()->name)->toBe('Мощность двигателя')
            ->and($product->fresh()->specs())->toBe([['name' => 'Мощность двигателя', 'value' => '900 Вт']]);
    });

    it('locks the type while products have values (FL-01)', function (): void {
        actingAs(User::factory()->admin()->create());
        [$attribute] = listAttributeWithValues();
        $product = Product::factory()->create();
        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => ['Красный']]]);

        Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
            ->assertFormFieldDisabled('type')
            ->assertSee(__('attributes.errors.type_locked', ['count' => 1]));

        // A forged request must not change the type either: the service refuses.
        Livewire::test(EditAttribute::class, ['record' => $attribute->getRouteKey()])
            ->set('data.type', 'number')
            ->call('save');

        expect($attribute->fresh()->type)->toBe(AttributeType::List);

        $free = Attribute::factory()->create();

        Livewire::test(EditAttribute::class, ['record' => $free->getRouteKey()])->assertFormFieldEnabled('type');
    });
});

describe('values (FL-02)', function (): void {
    function valuesManager(Attribute $attribute)
    {
        return Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $attribute, 'pageClass' => EditAttribute::class]);
    }

    it('shows the manager only for list characteristics', function (): void {
        actingAs(User::factory()->admin()->create());
        $list = Attribute::factory()->create();
        $number = Attribute::factory()->number()->create();

        expect(ValuesRelationManager::canViewForRecord($list, EditAttribute::class))->toBeTrue()
            ->and(ValuesRelationManager::canViewForRecord($number, EditAttribute::class))->toBeFalse();
    });

    it('adds a value, refuses a duplicate and renames', function (): void {
        actingAs(User::factory()->admin()->create());
        $attribute = Attribute::factory()->create();

        valuesManager($attribute)->callAction(TestAction::make('create')->table(), ['value' => 'Зелёный']);

        $value = AttributeValue::query()->where('attribute_id', $attribute->id)->firstOrFail();

        expect($value->value)->toBe('Зелёный')->and($value->slug)->not->toBe('');

        // Adding a text that is already in the dictionary does not create a second value.
        valuesManager($attribute)->callAction(TestAction::make('create')->table(), ['value' => 'зелёный']);

        expect(AttributeValue::query()->where('attribute_id', $attribute->id)->count())->toBe(1);

        $other = attrService()->createValue($attribute, 'Красный');

        valuesManager($attribute)->callAction(TestAction::make('edit')->table($other), ['value' => 'ЗЕЛЁНЫЙ'])
            ->assertNotified(__('attributes.errors.value_exists', ['value' => 'ЗЕЛЁНЫЙ']));

        valuesManager($attribute)->callAction(TestAction::make('edit')->table($value), ['value' => 'Изумрудный']);

        expect($value->fresh()->value)->toBe('Изумрудный');
    });

    it('reorders values by dragging and rebuilds the JSON order', function (): void {
        actingAs(User::factory()->admin()->create());
        [$attribute, $red, $blue] = listAttributeWithValues();
        $product = Product::factory()->create();
        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => ['Красный', 'Синий']]]);

        expect($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Красный, Синий']]);

        valuesManager($attribute)->call('reorderTable', [$blue->id, $red->id]);

        expect($attribute->values()->pluck('id')->all())->toBe([$blue->id, $red->id])
            ->and($product->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Синий, Красный']]);
    });

    it('merges a value into another one and moves the products', function (): void {
        actingAs(User::factory()->admin()->create());
        [$attribute, $red, $blue] = listAttributeWithValues();
        $one = Product::factory()->create();
        $both = Product::factory()->create();
        attrService()->setProductValues($one, [['attribute_id' => $attribute->id, 'values' => ['Красный']]]);
        attrService()->setProductValues($both, [['attribute_id' => $attribute->id, 'values' => ['Красный', 'Синий']]]);

        valuesManager($attribute)->callAction(TestAction::make('merge')->table($red), ['target' => $blue->id]);

        expect(AttributeValue::query()->whereKey($red->id)->exists())->toBeFalse()
            ->and(ProductAttributeValue::query()->where('attribute_value_id', $blue->id)->count())->toBe(2)
            ->and($one->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Синий']])
            ->and($both->fresh()->specs())->toBe([['name' => 'Цвет', 'value' => 'Синий']]);
    });

    it('refuses to delete a used value with the number of products and deletes an unused one', function (): void {
        actingAs(User::factory()->admin()->create());
        [$attribute, $red, $blue] = listAttributeWithValues();
        $product = Product::factory()->create();
        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => ['Красный']]]);

        valuesManager($attribute)->callAction(TestAction::make('delete')->table($red))
            ->assertNotified(__('attributes.errors.value_in_use', ['value' => 'Красный', 'count' => 1]));

        expect(AttributeValue::query()->whereKey($red->id)->exists())->toBeTrue();

        valuesManager($attribute)->callAction(TestAction::make('delete')->table($blue));

        expect(AttributeValue::query()->whereKey($blue->id)->exists())->toBeFalse();
    });
});

describe('deletion (FL-20)', function (): void {
    it('deletes an unused characteristic', function (): void {
        actingAs(User::factory()->admin()->create());
        $attribute = Attribute::factory()->create();

        Livewire::test(ListAttributes::class)->callAction(TestAction::make('delete')->table($attribute));

        expect(Attribute::query()->whereKey($attribute->id)->exists())->toBeFalse();
    });

    it('refuses a used one with the number of products and offers «Отвязать от товаров и удалить»', function (): void {
        actingAs(User::factory()->admin()->create());
        [$attribute] = listAttributeWithValues();
        $product = Product::factory()->create();
        attrService()->setProductValues($product, [['attribute_id' => $attribute->id, 'values' => ['Красный']]]);

        Livewire::test(ListAttributes::class)
            ->assertTableActionVisible('detach_delete', $attribute)
            ->callAction(TestAction::make('delete')->table($attribute))
            ->assertNotified(__('attributes.errors.in_use', ['count' => 1]));

        expect(Attribute::query()->whereKey($attribute->id)->exists())->toBeTrue();

        Livewire::test(ListAttributes::class)->callAction(TestAction::make('detach_delete')->table($attribute));

        expect(Attribute::query()->whereKey($attribute->id)->exists())->toBeFalse()
            ->and(ProductAttributeValue::query()->count())->toBe(0)
            // The JSON cache is rebuilt by the queued job (sync in tests).
            ->and($product->fresh()->specs())->toBe([]);
    });

    it('needs attributes.delete', function (): void {
        actingAs(staffWith(['dashboard.view', 'attributes.view', 'attributes.update']));
        [$attribute] = listAttributeWithValues();
        Product::factory()->create();

        Livewire::test(ListAttributes::class)->assertTableActionHidden('detach_delete', $attribute);
    });
});

describe('«Показать в фильтрах категорий…» (FL-17)', function (): void {
    it('adds the characteristic to the chosen categories and respects the maximum', function (): void {
        actingAs(User::factory()->admin()->create());
        $settings = app(CatalogSettings::class);
        $settings->filters_max_per_category = 2;
        $settings->save();

        $attribute = Attribute::factory()->create(['name' => 'Цвет']);
        $other = Attribute::factory()->count(2)->create();
        $empty = Category::factory()->create();
        $full = Category::factory()->create();
        $has = Category::factory()->create();
        CategoryFilter::factory()->create(['category_id' => $full->id, 'attribute_id' => $other[0]->id]);
        CategoryFilter::factory()->create(['category_id' => $full->id, 'attribute_id' => $other[1]->id]);
        CategoryFilter::factory()->create(['category_id' => $has->id, 'attribute_id' => $attribute->id]);

        Livewire::test(ListAttributes::class)
            ->callAction(TestAction::make('show_in_filters')->table($attribute), ['categories' => [$empty->id, $full->id, $has->id]])
            ->assertNotified(__('admin_attributes.actions.show_in_filters_done', ['added' => 1, 'already' => 1, 'full' => 1]));

        expect(CategoryFilter::query()->where('category_id', $empty->id)->where('attribute_id', $attribute->id)->exists())->toBeTrue()
            ->and(CategoryFilter::query()->where('category_id', $full->id)->count())->toBe(2)
            ->and(CategoryFilter::query()->where('category_id', $has->id)->count())->toBe(1);
    });

    it('copies the inherited set when the category has none of its own', function (): void {
        actingAs(User::factory()->admin()->create());
        $attribute = Attribute::factory()->create();
        $inherited = Attribute::factory()->create();
        $parent = Category::factory()->create();
        $child = Category::factory()->childOf($parent)->create();
        CategoryFilter::factory()->create(['category_id' => $parent->id, 'attribute_id' => $inherited->id]);

        Livewire::test(ListAttributes::class)
            ->callAction(TestAction::make('show_in_filters')->table($attribute), ['categories' => [$child->id]]);

        expect(CategoryFilter::query()->where('category_id', $child->id)->orderBy('sort_order')->pluck('attribute_id')->all())->toBe([$inherited->id, $attribute->id])
            ->and(CategoryFilter::query()->where('category_id', $parent->id)->count())->toBe(1);
    });

    it('is not offered without categories.update', function (): void {
        actingAs(staffWith(['dashboard.view', 'attributes.view', 'attributes.update']));
        $attribute = Attribute::factory()->create();

        Livewire::test(ListAttributes::class)->assertTableActionHidden('show_in_filters', $attribute);
    });
});

describe('migration from JSON (FL-04)', function (): void {
    function unmigrate(): void
    {
        $settings = app(CatalogSettings::class);
        $settings->attributes_migrated_at = null;
        $settings->save();
    }

    it('previews the dry run in the modal without writing, then queues the job on confirmation', function (): void {
        Queue::fake([MigrateAttributesFromJson::class]);
        unmigrate();
        actingAs(User::factory()->admin()->create());
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный'], ['name' => 'Мощность, Вт', 'value' => '900']]]);
        Product::factory()->create(['attributes' => [['name' => 'цвет', 'value' => 'Синий']]]);

        $preview = Livewire::test(ListAttributes::class)->mountAction('migrate_from_json');

        // The dry run found the characteristics, merged «Цвет» / «цвет» and stored the report for the modal.
        expect($preview->instance()->getMountedAction()?->getModalContent()?->render())
            ->toContain(__('admin_attributes.migration.products', ['count' => 2]))
            ->toContain(__('admin_attributes.migration.to_create', ['count' => 2]))
            ->toContain('Мощность')
            ->toContain(__('admin_attributes.migration.merged'));

        // The preview wrote nothing and queued nothing.
        Queue::assertNothingPushed();
        expect(Attribute::query()->count())->toBe(0);

        Livewire::test(ListAttributes::class)
            ->callAction('migrate_from_json')
            ->assertNotified(__('admin_attributes.migration.queued'));

        Queue::assertPushed(MigrateAttributesFromJson::class, 1);
        expect(Attribute::query()->count())->toBe(0);
    });

    it('shows the values that would be lost and requires an explicit confirmation to start', function (): void {
        Queue::fake([MigrateAttributesFromJson::class]);
        unmigrate();
        actingAs(User::factory()->admin()->create());

        foreach (range(1, 40) as $i) {
            Product::factory()->create(['attributes' => [['name' => 'Вес, кг', 'value' => (string) $i]]]);
        }

        Product::factory()->create(['attributes' => [['name' => 'Вес, кг', 'value' => 'около тонны']]]);

        $preview = Livewire::test(ListAttributes::class)->mountAction('migrate_from_json');

        expect($preview->instance()->getMountedAction()?->getModalContent()?->render())
            ->toContain(__('admin_attributes.migration.loss', ['values' => 1, 'products' => 1]))
            ->toContain('около тонны');

        // Without the checkbox nothing is queued.
        Livewire::test(ListAttributes::class)
            ->callAction('migrate_from_json')
            ->assertHasActionErrors(['accept_loss']);
        Queue::assertNothingPushed();

        Livewire::test(ListAttributes::class)
            ->callAction('migrate_from_json', ['accept_loss' => true])
            ->assertHasNoActionErrors()
            ->assertNotified(__('admin_attributes.migration.queued'));
        Queue::assertPushed(MigrateAttributesFromJson::class, 1);
    });

    it('does not ask for the confirmation when nothing would be lost', function (): void {
        Queue::fake([MigrateAttributesFromJson::class]);
        unmigrate();
        actingAs(User::factory()->admin()->create());
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);

        Livewire::test(ListAttributes::class)
            ->callAction('migrate_from_json')
            ->assertHasNoActionErrors();

        Queue::assertPushed(MigrateAttributesFromJson::class, 1);
    });

    it('migrates when the queued job runs and then hides the action', function (): void {
        unmigrate();
        actingAs(User::factory()->admin()->create());
        $product = Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);

        Livewire::test(ListAttributes::class)->callAction('migrate_from_json');
        // The first run only prepares; the job queued from it runs after it on the database queue.
        MigrateAttributesFromJson::dispatch();

        expect(app(AttributeMigrationState::class)->isMigrated())->toBeTrue()
            ->and(Attribute::query()->where('name', 'Цвет')->exists())->toBeTrue()
            ->and(ProductAttributeValue::query()->where('product_id', $product->id)->count())->toBe(1);

        Livewire::test(ListAttributes::class)->assertActionHidden('migrate_from_json');
    });

    it('is only offered with attributes.update', function (): void {
        unmigrate();
        actingAs(staffWith(['dashboard.view', 'attributes.view']));

        Livewire::test(ListAttributes::class)->assertActionHidden('migrate_from_json');
    });

    it('shows the pending banner with the progress «N из M»', function (): void {
        unmigrate();
        actingAs(User::factory()->admin()->create());
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);

        expect(AttributeMigrationWidget::canView())->toBeTrue();

        Livewire::test(ListAttributes::class)->assertSeeLivewire(AttributeMigrationWidget::class);

        app(AttributeMigrationState::class)->begin(10, 1);
        app(AttributeMigrationState::class)->advance(5, 4, 0);

        Livewire::test(AttributeMigrationWidget::class)
            ->assertSee(__('dashboard.attributes.notice'))
            ->assertSee(__('dashboard.attributes.progress', ['done' => 4, 'total' => 10]));

        attrMigrated();

        expect(AttributeMigrationWidget::canView())->toBeFalse();
    });
});
