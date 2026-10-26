<?php

declare(strict_types=1);

use App\Enums\PriceRuleAction;
use App\Enums\PriceRuleScope;
use App\Enums\PriceRuleTargetType;
use App\Enums\ProductFlag;
use App\Filament\Resources\PriceRules\Pages\CreatePriceRule;
use App\Filament\Resources\PriceRules\Pages\EditPriceRule;
use App\Filament\Resources\PriceRules\Pages\ListPriceRules;
use App\Filament\Resources\PriceRules\Pages\ViewPriceRule;
use App\Filament\Resources\PriceRules\PriceRuleResource;
use App\Filament\Resources\PriceRules\Schemas\PriceRuleForm;
use App\Models\Category;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PriceRulePreview;
use App\Services\Pricing\PriceRuleRecalculator;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ruleForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Распродажа',
        'is_active' => true,
        'action' => PriceRuleAction::DiscountPercent->value,
        'value' => '10',
        'scope' => PriceRuleScope::All->value,
        'priority' => 0,
    ], $overrides);
}

it('renders list, create, view and edit pages for admin', function (): void {
    actingAs(User::factory()->admin()->create());
    $rule = PriceRule::factory()->discountPercent(10)->create(['name' => 'Чёрная пятница']);

    Livewire::test(ListPriceRules::class)->assertOk()->assertCanSeeTableRecords([$rule])
        ->assertSee('Чёрная пятница')
        ->assertSee('Скидка 10'.Money::CURRENCY_SEPARATOR.'%');
    Livewire::test(CreatePriceRule::class)->assertOk();
    Livewire::test(ViewPriceRule::class, ['record' => $rule->getRouteKey()])->assertOk();
    Livewire::test(EditPriceRule::class, ['record' => $rule->getRouteKey()])->assertOk()
        ->assertSchemaStateSet(['name' => 'Чёрная пятница', 'action' => 'discount_percent', 'value' => '10', 'scope' => 'all']);

    $this->get(PriceRuleResource::getUrl('index'))->assertOk();
});

it('shows action and scope summaries in the list', function (): void {
    actingAs(User::factory()->admin()->create());
    $category = Category::factory()->create(['name' => 'Освещение']);
    PriceRule::factory()->markupFixed(500)->forCategories([$category])->create();
    PriceRule::factory()->forBrands(['Север', 'Уют'])->create();
    PriceRule::factory()->forFlags([ProductFlag::Featured])->create();

    Livewire::test(ListPriceRules::class)
        ->assertSee('Наценка '.Money::format(50_000))
        ->assertSee('Категории: Освещение (+ подкатегории)')
        ->assertSee('Бренды: Север, Уют')
        ->assertSee('Метки: Хит');
});

describe('create', function (): void {
    beforeEach(function (): void {
        actingAs(User::factory()->admin()->create());
    });

    it('creates a rule for all products and recalculates final prices', function (): void {
        $product = Product::factory()->create(['base_price' => '1000.00']);

        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['value' => '12,5', 'show_as_discount' => true, 'priority' => 5]))
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = PriceRule::query()->sole();

        expect($rule->value)->toBe('12.5000')
            ->and($rule->action)->toBe(PriceRuleAction::DiscountPercent)
            ->and($rule->scope)->toBe(PriceRuleScope::All)
            ->and($rule->show_as_discount)->toBeTrue()
            ->and($rule->priority)->toBe(5)
            ->and($rule->targets)->toHaveCount(0)
            ->and($product->fresh()->final_price)->toBe('875.00')
            ->and($product->fresh()->display_old_price)->toBe('1000.00');
    });

    it('stores category targets and the subcategories flag', function (): void {
        $root = Category::factory()->create();
        $child = Category::factory()->childOf($root)->create();
        $other = Category::factory()->create();
        $inChild = Product::factory()->inCategory($child)->create(['base_price' => '1000.00']);
        $inOther = Product::factory()->inCategory($other)->create(['base_price' => '1000.00']);

        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm([
                'scope' => 'categories',
                'category_ids' => [$root->id],
                'include_subcategories' => true,
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = PriceRule::query()->with('targets')->sole();

        expect($rule->scope)->toBe(PriceRuleScope::Categories)
            ->and($rule->include_subcategories)->toBeTrue()
            ->and($rule->categoryIds())->toBe([$root->id])
            ->and($inChild->fresh()->final_price)->toBe('900.00')
            ->and($inOther->fresh()->final_price)->toBe('1000.00');
    });

    it('stores product targets', function (): void {
        $a = Product::factory()->create(['base_price' => '1000.00']);
        $b = Product::factory()->create(['base_price' => '1000.00']);

        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['scope' => 'products', 'product_ids' => [$a->id]]))
            ->call('create')
            ->assertHasNoFormErrors();

        expect(PriceRule::query()->with('targets')->sole()->productIds())->toBe([$a->id])
            ->and($a->fresh()->final_price)->toBe('900.00')
            ->and($b->fresh()->final_price)->toBe('1000.00');
    });

    it('stores brand targets', function (): void {
        $a = Product::factory()->create(['brand' => 'Аврора', 'base_price' => '1000.00']);
        $b = Product::factory()->create(['brand' => 'Север', 'base_price' => '1000.00']);

        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['scope' => 'brands', 'brands' => ['аврора', 'Мастер']]))
            ->call('create')
            ->assertHasNoFormErrors();

        expect(PriceRule::query()->with('targets')->sole()->brands())->toBe(['аврора', 'Мастер'])
            ->and($a->fresh()->final_price)->toBe('900.00')
            ->and($b->fresh()->final_price)->toBe('1000.00');
    });

    it('stores flag targets', function (): void {
        $hit = Product::factory()->create(['is_featured' => true, 'base_price' => '1000.00']);
        $plain = Product::factory()->create(['base_price' => '1000.00']);

        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['scope' => 'flags', 'flags' => ['featured']]))
            ->call('create')
            ->assertHasNoFormErrors();

        expect(PriceRule::query()->with('targets')->sole()->flags())->toBe([ProductFlag::Featured])
            ->and($hit->fresh()->final_price)->toBe('900.00')
            ->and($plain->fresh()->final_price)->toBe('1000.00');
    });

    it('ignores targets of other scopes', function (): void {
        $category = Category::factory()->create();
        $product = Product::factory()->create();

        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['scope' => 'products', 'product_ids' => [$product->id], 'category_ids' => [$category->id]]))
            ->call('create')
            ->assertHasNoFormErrors();

        $targets = PriceRule::query()->sole()->targets;

        expect($targets)->toHaveCount(1)
            ->and($targets[0]->target_type)->toBe(PriceRuleTargetType::Product);
    });

    it('hides show_as_discount for markup actions and does not store it', function (): void {
        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['action' => 'markup_percent', 'show_as_discount' => true]))
            ->assertFormFieldHidden('show_as_discount')
            ->call('create')
            ->assertHasNoFormErrors();

        expect(PriceRule::query()->sole()->show_as_discount)->toBeFalse();
    });

    it('stores a period', function (): void {
        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['starts_at' => '2030-11-01 10:00:00', 'ends_at' => '2030-11-30 23:00:00']))
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = PriceRule::query()->sole();

        expect($rule->starts_at?->format('Y-m-d H:i'))->toBe('2030-11-01 10:00')
            ->and($rule->ends_at?->format('Y-m-d H:i'))->toBe('2030-11-30 23:00');
    });

    it('validates the value', function (array $overrides, string $error): void {
        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm($overrides))
            ->call('create')
            ->assertHasFormErrors(['value' => $error]);

        expect(PriceRule::query()->count())->toBe(0);
    })->with([
        'zero' => [['value' => '0'], 'Введите число больше нуля.'],
        'negative' => [['value' => '-5'], 'Введите число больше нуля.'],
        'text' => [['value' => 'abc'], 'Введите число больше нуля.'],
        'too many decimals for percent' => [['value' => '1.12345'], 'Слишком много знаков после запятой (допустимо до 4), в целой части не более 8 цифр.'],
        'kopecks fraction for rubles' => [['action' => 'markup_fixed', 'value' => '10.123'], 'Слишком много знаков после запятой (допустимо до 2), в целой части не более 8 цифр.'],
        'discount over 100 %' => [['value' => '100.5'], 'Значение не должно быть больше 100.'],
    ]);

    it('requires a value', function (): void {
        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['value' => null]))
            ->call('create')
            ->assertHasFormErrors(['value' => 'required']);
    });

    it('requires ends_at to be later than starts_at', function (): void {
        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['starts_at' => '2030-11-30 10:00:00', 'ends_at' => '2030-11-01 10:00:00']))
            ->call('create')
            ->assertHasFormErrors(['ends_at' => 'Окончание должно быть позже начала.']);

        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['starts_at' => '2030-11-01 10:00:00', 'ends_at' => '2030-11-01 10:00:00']))
            ->call('create')
            ->assertHasFormErrors(['ends_at' => 'Окончание должно быть позже начала.']);

        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['ends_at' => '2030-11-01 10:00:00']))
            ->call('create')
            ->assertHasNoFormErrors();
    });

    it('requires targets for the chosen scope', function (string $scope, string $field): void {
        Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['scope' => $scope]))
            ->call('create')
            ->assertHasFormErrors([$field => 'required']);
    })->with([
        ['categories', 'category_ids'],
        ['products', 'product_ids'],
        ['brands', 'brands'],
        ['flags', 'flags'],
    ]);
});

describe('edit and delete', function (): void {
    beforeEach(function (): void {
        actingAs(User::factory()->admin()->create());
    });

    it('replaces targets and recalculates products of both the old and the new scope', function (): void {
        $a = Product::factory()->create(['brand' => 'Север', 'base_price' => '1000.00']);
        $b = Product::factory()->create(['brand' => 'Уют', 'base_price' => '1000.00']);
        $rule = PriceRule::factory()->discountPercent(10)->forBrands(['Север'])->create();
        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));
        expect($a->fresh()->final_price)->toBe('900.00');

        Livewire::test(EditPriceRule::class, ['record' => $rule->getRouteKey()])
            ->assertSchemaStateSet(['brands' => ['Север']])
            ->fillForm(['brands' => ['Уют'], 'value' => '20'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($rule->fresh()->value)->toBe('20.0000')
            ->and($rule->targets()->count())->toBe(1)
            ->and($a->fresh()->final_price)->toBe('1000.00')
            ->and($b->fresh()->final_price)->toBe('800.00');
    });

    it('deletes a rule and restores the prices it affected', function (): void {
        $product = Product::factory()->create(['base_price' => '1000.00']);
        $rule = PriceRule::factory()->discountPercent(10)->create();
        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));
        expect($product->fresh()->final_price)->toBe('900.00');

        Livewire::test(EditPriceRule::class, ['record' => $rule->getRouteKey()])
            ->callAction('delete');

        expect(PriceRule::query()->count())->toBe(0)
            ->and($product->fresh()->final_price)->toBe('1000.00');
    });

    it('deletes a rule from the table', function (): void {
        $product = Product::factory()->create(['base_price' => '1000.00']);
        $rule = PriceRule::factory()->discountPercent(10)->create();
        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));

        Livewire::test(ListPriceRules::class)
            ->callTableAction('delete', $rule);

        expect(PriceRule::query()->count())->toBe(0)
            ->and($product->fresh()->final_price)->toBe('1000.00');
    });

    it('recalculates when the rule is toggled in the table', function (): void {
        $product = Product::factory()->create(['base_price' => '1000.00']);
        $rule = PriceRule::factory()->discountPercent(10)->inactive()->create();

        Livewire::test(ListPriceRules::class)
            ->call('updateTableColumnState', 'is_active', $rule->getKey(), true);

        expect($rule->fresh()->is_active)->toBeTrue()
            ->and($product->fresh()->final_price)->toBe('900.00');

        Livewire::test(ListPriceRules::class)
            ->call('updateTableColumnState', 'is_active', $rule->getKey(), false);

        expect($product->fresh()->final_price)->toBe('1000.00');
    });

    it('filters the list', function (): void {
        $active = PriceRule::factory()->discountPercent(10)->create();
        $inactive = PriceRule::factory()->markupFixed(5)->inactive()->create();
        $future = PriceRule::factory()->between(now()->addDay(), null)->create();
        $current = PriceRule::factory()->between(now()->subDay(), now()->addDay())->create();
        $brands = PriceRule::factory()->forBrands(['Север'])->create();

        Livewire::test(ListPriceRules::class)
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active])
            ->resetTableFilters()
            ->filterTable('action', 'markup_fixed')
            ->assertCanSeeTableRecords([$inactive])
            ->assertCanNotSeeTableRecords([$active])
            ->resetTableFilters()
            ->filterTable('scope', 'brands')
            ->assertCanSeeTableRecords([$brands])
            ->assertCanNotSeeTableRecords([$active])
            ->resetTableFilters()
            ->filterTable('in_effect')
            ->assertCanSeeTableRecords([$active, $current])
            ->assertCanNotSeeTableRecords([$inactive, $future]);
    });
});

describe('manager', function (): void {
    beforeEach(function (): void {
        actingAs(User::factory()->manager()->create());
        $this->rule = PriceRule::factory()->discountPercent(10)->create();
    });

    it('can list and view rules', function (): void {
        Livewire::test(ListPriceRules::class)->assertOk()->assertCanSeeTableRecords([$this->rule]);
        Livewire::test(ViewPriceRule::class, ['record' => $this->rule->getRouteKey()])->assertOk();

        $this->get(PriceRuleResource::getUrl('index'))->assertOk();
        $this->get(PriceRuleResource::getUrl('view', ['record' => $this->rule]))->assertOk();
    });

    it('cannot create, edit or delete', function (): void {
        $this->get(PriceRuleResource::getUrl('create'))->assertForbidden();
        $this->get(PriceRuleResource::getUrl('edit', ['record' => $this->rule]))->assertForbidden();

        expect(PriceRuleResource::canCreate())->toBeFalse()
            ->and(PriceRuleResource::canEdit($this->rule))->toBeFalse()
            ->and(PriceRuleResource::canDelete($this->rule))->toBeFalse();
    });

    it('does not see the create button or row edit/delete actions', function (): void {
        Livewire::test(ListPriceRules::class)
            ->assertActionHidden('create')
            ->assertTableActionHidden('edit', $this->rule)
            ->assertTableActionHidden('delete', $this->rule)
            ->assertTableActionVisible('view', $this->rule);
    });

    it('cannot toggle the active switch', function (): void {
        Livewire::test(ListPriceRules::class)
            ->call('updateTableColumnState', 'is_active', $this->rule->getKey(), false);

        expect($this->rule->fresh()->is_active)->toBeTrue();
    });
});

describe('preview (PR-04)', function (): void {
    it('returns the count and the first 20 products with before and after prices', function (): void {
        $products = Product::factory()->count(25)->create(['base_price' => '1000.00', 'brand' => 'Север']);
        Product::factory()->create(['brand' => 'Уют', 'base_price' => '500.00']);

        $draft = new PriceRuleData(0, 'Черновик', PriceRuleAction::DiscountPercent, '10', PriceRuleScope::Brands, brands: ['север']);

        $preview = app(PriceRulePreview::class)->preview($draft);

        expect($preview['count'])->toBe(25)
            ->and($preview['rows'])->toHaveCount(20)
            ->and($preview['rows'][0])->toBe([
                'id' => $products[0]->id,
                'name' => $products[0]->name,
                'sku' => $products[0]->sku,
                'before' => 100_000,
                'after' => 90_000,
            ]);
    });

    it('replaces the saved version of the edited rule instead of adding to it', function (): void {
        $product = Product::factory()->create(['base_price' => '1000.00']);
        $rule = PriceRule::factory()->discountPercent(10)->create();
        app(PriceRuleRecalculator::class)->ruleChanged(null, PriceRuleData::fromModel($rule->load('targets')));

        $edited = new PriceRuleData($rule->id, 'x', PriceRuleAction::DiscountPercent, '20');
        $added = new PriceRuleData(0, 'x', PriceRuleAction::DiscountPercent, '20');

        $replaced = app(PriceRulePreview::class)->preview($edited)['rows'][0];
        $stacked = app(PriceRulePreview::class)->preview($added)['rows'][0];

        expect($replaced['before'])->toBe(90_000)
            ->and($replaced['after'])->toBe(80_000)
            ->and($stacked['after'])->toBe(72_000)
            ->and($product->fresh()->final_price)->toBe('900.00');
    });

    it('shows the effect of a rule that has not started yet', function (): void {
        Product::factory()->create(['base_price' => '1000.00']);
        $draft = new PriceRuleData(0, 'x', PriceRuleAction::MarkupFixed, '100', startsAt: now()->addMonth()->toImmutable());

        expect(app(PriceRulePreview::class)->preview($draft)['rows'][0]['after'])->toBe(110_000);
    });

    it('renders the preview of the current form state', function (): void {
        actingAs(User::factory()->admin()->create());
        Product::factory()->create(['name' => 'Кресло предпросмотра', 'base_price' => '1290.00']);

        $html = PriceRuleForm::previewView(ruleForm(['value' => '10']), null)->render();

        expect($html)->toContain('Затронуто товаров: 1')
            ->and($html)->toContain('Кресло предпросмотра')
            ->and($html)->toContain(Money::format(129_000))
            ->and($html)->toContain(Money::format(116_100));

        expect(PriceRuleForm::previewView(ruleForm(['value' => '']), null)->render())
            ->toContain('Заполните действие');
    });

    it('opens from the form action with the current form state', function (): void {
        actingAs(User::factory()->admin()->create());
        Product::factory()->create(['name' => 'Кресло в окне', 'base_price' => '1000.00']);

        $action = TestAction::make('preview')->schemaComponent('preview_actions', schema: 'form');

        $component = Livewire::test(CreatePriceRule::class)
            ->fillForm(ruleForm(['value' => '10']))
            ->mountAction($action)
            ->assertActionMounted($action);

        $content = $component->instance()->getMountedAction()?->getModalContent();

        expect($content?->toHtml())->toContain('Кресло в окне')->toContain('Затронуто товаров: 1');
    });
});
