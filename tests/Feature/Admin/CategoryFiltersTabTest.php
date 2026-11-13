<?php

declare(strict_types=1);

use App\Exceptions\AttributeException;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\CategoryFilter;
use App\Models\OptionType;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CatalogVersion;
use App\Services\Catalog\CategoryFilterEditor;
use App\Services\Variants\CategoryFilterRows;
use App\Settings\CatalogSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function filterAction(string $name): TestAction
{
    return TestAction::make($name)->schemaComponent('filters_actions', schema: 'form');
}

/**
 * @return list<array{attribute_id: int, is_expanded: bool}>
 */
function savedFilterRows(Category $category): array
{
    return CategoryFilter::query()->where('category_id', $category->id)->orderBy('sort_order')->get()
        ->map(fn (CategoryFilter $row): array => ['attribute_id' => (int) $row->attribute_id, 'is_expanded' => $row->is_expanded])->all();
}

beforeEach(function (): void {
    attrMigrated();
    actingAs(User::factory()->admin()->create());
    $this->color = Attribute::factory()->create(['name' => 'Цвет']);
    $this->size = Attribute::factory()->create(['name' => 'Размер']);
    $this->power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
});

describe('tab «Фильтры» (FL-05, FL-06, FL-17)', function (): void {
    it('is shown on the edit page only', function (): void {
        $category = Category::factory()->create();

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])->assertSee(__('admin_attributes.category.tab'));
        Livewire::test(CreateCategory::class)->assertDontSee(__('admin_attributes.category.tab'));
    });

    it('shows «Набор унаследован от …» for a category without an own set', function (): void {
        $parent = Category::factory()->create(['name' => 'Одежда']);
        $child = Category::factory()->childOf($parent)->create();
        CategoryFilter::factory()->create(['category_id' => $parent->id, 'attribute_id' => $this->color->id]);

        $component = Livewire::test(EditCategory::class, ['record' => $child->getRouteKey()])
            ->assertSee(__('admin_attributes.category.inherited_from', ['name' => 'Одежда']));

        expect($component->get('data.filters_own'))->toBeFalse();
    });

    it('says that no set is defined when nobody has one', function (): void {
        $category = Category::factory()->create();

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->assertSee(__('admin_attributes.category.no_set'));
    });

    it('starts an own set as a copy of the inherited one and saves it in the order of the editor', function (): void {
        $parent = Category::factory()->create(['name' => 'Одежда']);
        $child = Category::factory()->childOf($parent)->create();
        CategoryFilter::factory()->create(['category_id' => $parent->id, 'attribute_id' => $this->color->id, 'sort_order' => 10, 'is_expanded' => true]);

        $component = Livewire::test(EditCategory::class, ['record' => $child->getRouteKey()])
            ->callAction(filterAction('own_filter_set'));

        expect($component->get('data.filters_own'))->toBeTrue()
            ->and(array_values(array_map(fn (array $row): int => (int) $row['attribute_id'], $component->get('data.filters'))))->toBe([$this->color->id]);

        $component
            ->fillForm(['filters' => [
                ['source' => 'attribute', 'attribute_id' => $this->power->id, 'is_expanded' => false],
                ['source' => 'attribute', 'attribute_id' => $this->color->id, 'is_expanded' => true],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(savedFilterRows($child))->toBe([
            ['attribute_id' => $this->power->id, 'is_expanded' => false],
            ['attribute_id' => $this->color->id, 'is_expanded' => true],
        ])->and(savedFilterRows($parent))->toHaveCount(1);
    });

    it('resets to the inherited set: the own rows are removed on save', function (): void {
        $parent = Category::factory()->create(['name' => 'Одежда']);
        $child = Category::factory()->childOf($parent)->create();
        CategoryFilter::factory()->create(['category_id' => $parent->id, 'attribute_id' => $this->color->id]);
        CategoryFilter::factory()->create(['category_id' => $child->id, 'attribute_id' => $this->size->id]);

        $component = Livewire::test(EditCategory::class, ['record' => $child->getRouteKey()]);

        expect($component->get('data.filters_own'))->toBeTrue();

        $component->callAction(filterAction('reset_filter_set'))
            ->assertSee(__('admin_attributes.category.inherited_from', ['name' => 'Одежда']))
            ->call('save')
            ->assertHasNoFormErrors();

        expect(savedFilterRows($child))->toBe([])
            ->and(app(CategoryFilterEditor::class)->state($child)['inherited_name'])->toBe('Одежда');
    });

    it('bumps the catalog version on save', function (): void {
        $category = Category::factory()->create();
        $before = CatalogVersion::current();

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction(filterAction('own_filter_set'))
            ->fillForm(['filters' => [['source' => 'attribute', 'attribute_id' => $this->color->id, 'is_expanded' => false]]])
            ->call('save');

        expect(CatalogVersion::current())->not->toBe($before);
    });

    it('limits the set to filters_max_per_category', function (): void {
        $settings = app(CatalogSettings::class);
        $settings->filters_max_per_category = 2;
        $settings->save();
        $category = Category::factory()->create();

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction(filterAction('own_filter_set'))
            ->fillForm(['filters' => [
                ['source' => 'attribute', 'attribute_id' => $this->color->id],
                ['source' => 'attribute', 'attribute_id' => $this->size->id],
                ['source' => 'attribute', 'attribute_id' => $this->power->id],
            ]])
            ->call('save')
            ->assertHasFormErrors();

        expect(savedFilterRows($category))->toBe([]);

        // The service enforces it as well, whatever the form sends.
        expect(fn () => app(CategoryFilterEditor::class)->save($category, true, [
            ['attribute_id' => $this->color->id], ['attribute_id' => $this->size->id], ['attribute_id' => $this->power->id],
        ]))->toThrow(AttributeException::class);
    });

    it('does not allow the same characteristic twice', function (): void {
        $category = Category::factory()->create();

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction(filterAction('own_filter_set'))
            ->fillForm(['filters' => [
                ['source' => 'attribute', 'attribute_id' => $this->color->id],
                ['source' => 'attribute', 'attribute_id' => $this->color->id],
            ]])
            ->call('save')
            ->assertHasFormErrors();
    });

    it('saves a filterable option as a filter next to characteristics, in the order of the editor (VR-19)', function (): void {
        $category = Category::factory()->create();
        $color = OptionType::factory()->color()->filterable()->create(['name' => 'Цвет']);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction(filterAction('own_filter_set'))
            ->fillForm(['filters' => [
                ['source' => 'option', 'option_type_id' => $color->id, 'is_expanded' => true],
                ['source' => 'attribute', 'attribute_id' => $this->color->id, 'is_expanded' => false],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $rows = CategoryFilter::query()->where('category_id', $category->id)->orderBy('sort_order')->get();

        expect($rows->map(fn (CategoryFilter $row): string => $row->source->value)->all())->toBe(['option', 'attribute'])
            ->and($rows[0]->option_type_id)->toBe($color->id)
            ->and($rows[0]->attribute_id)->toBeNull()
            ->and($rows[0]->is_expanded)->toBeTrue()
            ->and($rows[1]->attribute_id)->toBe($this->color->id);

        $component = Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()]);

        expect($component->get('data.filters_own'))->toBeTrue()
            ->and((int) $component->get('data.filters.'.array_key_first($component->get('data.filters')).'.option_type_id'))->toBe($color->id);
    });

    it('offers only options with the filter flag and refuses another one', function (): void {
        $category = Category::factory()->create();
        $filterable = OptionType::factory()->filterable()->create(['name' => 'Размер']);
        $plain = OptionType::factory()->create(['name' => 'Материал упаковки']);

        expect(app(CategoryFilterRows::class)->optionChoices())->toBe([$filterable->id => 'Размер']);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->callAction(filterAction('own_filter_set'))
            ->fillForm(['filters' => [['source' => 'option', 'option_type_id' => $plain->id]]])
            ->call('save');

        expect(CategoryFilter::query()->where('category_id', $category->id)->count())->toBe(0);
    });

    it('copies the option rows of the inherited set into an own set and keeps an option that lost its flag', function (): void {
        $parent = Category::factory()->create();
        $child = Category::factory()->childOf($parent)->create();
        $size = OptionType::factory()->filterable()->create(['name' => 'Размер']);
        CategoryFilter::factory()->create(['category_id' => $parent->id, 'source' => 'option', 'attribute_id' => null, 'option_type_id' => $size->id, 'sort_order' => 10]);

        $component = Livewire::test(EditCategory::class, ['record' => $child->getRouteKey()])->callAction(filterAction('own_filter_set'));

        expect(array_values(array_map(fn (array $row): ?int => $row['option_type_id'], $component->get('data.filters'))))->toBe([$size->id]);

        $component->call('save')->assertHasNoFormErrors();
        $size->update(['is_filterable' => false]);

        Livewire::test(EditCategory::class, ['record' => $child->getRouteKey()])->call('save')->assertHasNoFormErrors();

        expect(CategoryFilter::query()->where('category_id', $child->id)->where('option_type_id', $size->id)->count())->toBe(1);
    });

    it('previews values and products per filter and warns when a filter has no values in the category', function (): void {
        $category = Category::factory()->create();
        $child = Category::factory()->childOf($category)->create();
        $outside = Category::factory()->create();

        foreach ([[$category, ['Красный']], [$child, ['Синий']], [$outside, ['Зелёный']]] as [$in, $values]) {
            $product = Product::factory()->inCategory($in)->create(['attributes' => null]);
            attrService()->setProductValues($product, [['attribute_id' => $this->color->id, 'values' => $values]]);
        }

        CategoryFilter::factory()->create(['category_id' => $category->id, 'attribute_id' => $this->color->id, 'sort_order' => 10]);
        CategoryFilter::factory()->create(['category_id' => $category->id, 'attribute_id' => $this->size->id, 'sort_order' => 20]);

        // The subcategory counts, the product outside does not.
        expect(app(CategoryFilterEditor::class)->preview($category, $this->color->id))->toBe(['products' => 2, 'values' => 2])
            ->and(app(CategoryFilterEditor::class)->preview($category, $this->size->id))->toBe(['products' => 0, 'values' => 0]);

        Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
            ->assertSee(__('admin_attributes.category.preview', ['values' => 2, 'products' => 2]))
            ->assertSee(__('admin_attributes.category.preview_empty'));
    });

    it('opens the category on the site with an example of the chosen filters (FL-19, FL-07)', function (): void {
        $category = Category::factory()->create(['slug' => 'instrument']);
        $one = Product::factory()->inCategory($category)->create(['attributes' => null]);
        $two = Product::factory()->inCategory($category)->create(['attributes' => null]);
        $wet = Attribute::factory()->boolean()->create(['name' => 'Влагозащита']);
        attrService()->setProductValues($one, [['attribute_id' => $this->color->id, 'values' => ['Красный']], ['attribute_id' => $this->power->id, 'values' => '500'], ['attribute_id' => $wet->id, 'values' => true]]);
        attrService()->setProductValues($two, [['attribute_id' => $this->color->id, 'values' => ['Красный']], ['attribute_id' => $this->power->id, 'values' => '900']]);

        $query = app(CategoryFilterEditor::class)->exampleQuery($category, [$this->color->id, $this->power->id, $wet->id, $this->size->id]);
        $red = AttributeValue::query()->where('value', 'Красный')->firstOrFail();

        expect($query)->toBe(['a' => [
            $this->color->slug => [$red->slug],
            $this->power->slug => ['min' => '500', 'max' => '900'],
            $wet->slug => '1',
        ]]);

        CategoryFilter::factory()->create(['category_id' => $category->id, 'attribute_id' => $this->color->id]);

        $component = Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()]);
        $html = $component->html();

        expect($html)->toContain(__('admin_attributes.category.open_on_site'))
            ->and($html)->toContain(e(route('catalog.category', 'instrument').'?'.http_build_query(['a' => [$this->color->slug => [$red->slug]]])));
    });
});
