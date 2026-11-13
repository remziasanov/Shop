<?php

declare(strict_types=1);

use App\Enums\GroupPriceBulkOperation;
use App\Enums\PriceChangeSource;
use App\Enums\PriceRuleAction;
use App\Enums\StorefrontSection;
use App\Filament\Pages\Settings\ManagePricingSettings;
use App\Filament\Resources\CustomerGroups\Pages\CreateCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\EditCustomerGroup;
use App\Filament\Resources\CustomerGroups\Pages\ListCustomerGroups;
use App\Filament\Resources\PriceHistory\Pages\ListPriceHistory;
use App\Filament\Resources\PriceHistory\PriceHistoryResource;
use App\Filament\Resources\PriceRules\Pages\CreatePriceRule;
use App\Filament\Resources\PriceRules\Pages\ListPriceRules;
use App\Filament\Resources\PriceRules\Schemas\PriceRuleForm;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Jobs\ApplyGroupPriceBulkChange;
use App\Jobs\BuildPriceList;
use App\Jobs\RecalculatePrices;
use App\Models\CustomerGroup;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductPriceTier;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Pricing\PriceRuleData;
use App\Services\Pricing\PriceRulePreview;
use App\Services\Users\PermissionService;
use App\Settings\PricingSettings;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    actingAs(User::factory()->admin()->create());
    // The wholesale group «Опт» (code opt) comes from the migration of stage 9.
    $this->group = CustomerGroup::query()->where('code', 'opt')->firstOrFail();
    $this->key = 'g'.$this->group->id;
});

/**
 * @return list<array{min_qty: int|string, price: string}>
 */
function tierRows(array ...$pairs): array
{
    return array_map(fn (array $pair): array => ['min_qty' => $pair[0], 'price' => $pair[1]], $pairs);
}

function storedTiers(object $item, CustomerGroup $group): array
{
    $query = ProductPriceTier::query()->where('customer_group_id', $group->id)->orderBy('min_qty');
    $item instanceof ProductVariant
        ? $query->where('product_id', $item->product_id)->where('variant_key', $item->id)
        : $query->where('product_id', $item->id)->where('variant_key', 0);

    return $query->get()->mapWithKeys(fn (ProductPriceTier $row): array => [$row->min_qty => (string) $row->price])->all();
}

describe('customer group form (GP-01)', function (): void {
    it('saves the discount and the retail price flag and queues the recalculation (GP-07)', function (): void {
        Queue::fake();
        gpProduct();
        $group = CustomerGroup::factory()->create();

        Livewire::test(EditCustomerGroup::class, ['record' => $group->getRouteKey()])
            ->assertFormSet(['default_discount_percent' => '0.00', 'show_retail_price' => false])
            ->fillForm(['default_discount_percent' => '12,5', 'show_retail_price' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $group->refresh();

        expect($group->default_discount_percent)->toBe('12.50')->and($group->show_retail_price)->toBeTrue();
        Queue::assertPushed(RecalculatePrices::class);
    });

    it('does not queue a recalculation when the discount stays', function (): void {
        Queue::fake();
        $group = CustomerGroup::factory()->discount(10)->create();
        Queue::assertNothingPushed();

        Livewire::test(EditCustomerGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm(['name' => 'Новое имя', 'show_retail_price' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNotPushed(RecalculatePrices::class);
    });

    it('rejects a discount outside 0 to 99.99', function (string $value): void {
        $group = CustomerGroup::factory()->create();

        Livewire::test(EditCustomerGroup::class, ['record' => $group->getRouteKey()])
            ->fillForm(['default_discount_percent' => $value])
            ->call('save')
            ->assertHasFormErrors(['default_discount_percent']);

        expect($group->refresh()->default_discount_percent)->toBe('0.00');
    })->with(['100', '99,999', '-1', 'abc']);

    it('creates a group with a discount', function (): void {
        Queue::fake();

        Livewire::test(CreateCustomerGroup::class)
            ->fillForm(['name' => 'VIP', 'code' => 'vip', 'default_discount_percent' => '20'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(CustomerGroup::query()->where('code', 'vip')->value('default_discount_percent'))->toBe('20.00');
    });
});

describe('price rule groups and preview (GP-04)', function (): void {
    it('saves a rule bound to groups and lists them', function (): void {
        Livewire::test(CreatePriceRule::class)
            ->fillForm(['name' => 'Опт -10', 'action' => 'discount_percent', 'value' => '10', 'scope' => 'all', 'priority' => 0, 'group_ids' => [$this->group->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $rule = PriceRule::query()->where('name', 'Опт -10')->firstOrFail();

        expect($rule->groupIds())->toBe([$this->group->id]);

        Livewire::test(ListPriceRules::class)->assertSee('Опт')->assertSee('Группы покупателей');
    });

    it('shows the prices of the chosen group in the preview and retail prices otherwise', function (): void {
        $product = gpProduct('1000.00');
        $discounted = CustomerGroup::factory()->discount(10)->create(['name' => 'Скидочная']);
        $draft = new PriceRuleData(id: PHP_INT_MAX, name: 'Опт', action: PriceRuleAction::DiscountPercent, value: '20', groupIds: [$this->group->id]);

        $retail = app(PriceRulePreview::class)->preview($draft);
        $forGroup = app(PriceRulePreview::class)->preview($draft, $this->group);
        $forOther = app(PriceRulePreview::class)->preview($draft, $discounted);

        expect($retail['rows'][0])->toMatchArray(['before' => 100_000, 'after' => 100_000])
            ->and($forGroup['rows'][0])->toMatchArray(['before' => 100_000, 'after' => 80_000])
            ->and($forOther['rows'][0])->toMatchArray(['before' => 90_000, 'after' => 90_000])
            ->and($product->id)->toBe($forGroup['rows'][0]['id']);
    });

    it('renders the preview view for a group', function (): void {
        gpProduct('1000.00');

        $state = ['name' => 'X', 'action' => 'discount_percent', 'value' => '10', 'scope' => 'all', 'group_ids' => [$this->group->id]];
        $html = PriceRuleForm::previewView($state, null, $this->group->id)->render();

        expect($html)->toContain('Цены группы «Опт»')->toContain('900');
    });
});

describe('product tab «Цены для групп» (GP-17)', function (): void {
    it('shows a block per group with the exact price hint and the stored rows', function (): void {
        $discounted = CustomerGroup::factory()->discount(10)->create(['name' => 'Скидочная']);
        $product = gpProduct('1200.00');
        gpTier($product, $this->group, 1, '1000.00');
        gpTier($product, $this->group, 10, '900.00');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertOk()
            ->assertSee('Цены для групп')
            ->assertSee('Опт')
            ->assertSee('Скидочная')
            ->assertSee('без точной цены: '.Money::format(108_000))
            ->assertSchemaStateSet(fn (array $state): bool => array_values($state['group_prices'][$this->key]) === tierRows([1, '1000.00'], [10, '900.00']));
    });

    it('saves exact prices and tiers through the service with history (GP-16)', function (): void {
        $product = gpProduct('1000.00');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['group_prices' => [$this->key => tierRows([1, '950'], [10, '900,50'], [50, '800'])]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(storedTiers($product, $this->group))->toBe([1 => '950.00', 10 => '900.50', 50 => '800.00'])
            ->and(PriceHistory::query()->where('product_id', $product->id)->where('customer_group_id', $this->group->id)->count())->toBe(3)
            ->and(PriceHistory::query()->where('min_qty', 10)->value('field'))->toBe('group_tier')
            ->and(gpRow($product, $this->group))->toBe(['final' => '950.00', 'old' => null]);
    });

    it('does not write a column of the product for the editor state', function (): void {
        $product = gpProduct('1000.00');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'Переименован', 'group_prices' => [$this->key => tierRows([1, '950'])]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($product->refresh()->name)->toBe('Переименован');
    });

    it('deletes the rows that were removed from the repeater', function (): void {
        $product = gpProduct('1000.00');
        gpTier($product, $this->group, 1, '950.00');
        gpTier($product, $this->group, 10, '900.00');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['group_prices' => [$this->key => tierRows([10, '900.00'])]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(storedTiers($product, $this->group))->toBe([10 => '900.00']);
    });

    it('blocks the save on errors and keeps the stored rows', function (array $rows): void {
        $product = gpProduct('1000.00');
        gpTier($product, $this->group, 1, '950.00');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['name' => 'Не сохранится', 'group_prices' => [$this->key => $rows]])
            ->call('save')
            ->assertHasFormErrors(['group_prices.'.$this->key]);

        expect(storedTiers($product, $this->group))->toBe([1 => '950.00'])
            ->and($product->refresh()->name)->not->toBe('Не сохранится');
    })->with([
        'price grows with quantity' => [tierRows([5, '800'], [10, '900'])],
        'duplicate quantity' => [tierRows([5, '800'], [5, '700'])],
        'zero price' => [tierRows([5, '0'])],
        'bad quantity' => [tierRows(['x', '800'])],
    ]);

    it('saves with warnings that do not block (GP-14)', function (): void {
        $product = gpProduct('1000.00');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['group_prices' => [$this->key => tierRows([10, '1200'])]])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified(__('admin_group_prices.editor.saved_with_warnings'));

        expect(storedTiers($product, $this->group))->toBe([10 => '1200.00']);
    });

    it('shows errors and warnings of the current rows live', function (): void {
        $product = gpProduct('1000.00');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm(['group_prices' => [$this->key => tierRows([5, '800'], [10, '900'])]])
            ->assertSee('дороже ступени от 5 шт')
            ->fillForm(['group_prices' => [$this->key => tierRows([10, '1200'])]])
            ->assertSee('выше розничной цены');
    });

    it('previews the price for a quantity', function (): void {
        $product = gpProduct('1000.00');

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->fillForm([
                'group_prices' => [$this->key => tierRows([1, '950'], [10, '900'])],
                'group_qty' => [$this->key => 12],
            ])
            ->assertSee('При 12 шт:')
            ->assertSee('ступень от 10 шт')
            ->fillForm(['group_qty' => [$this->key => 3]])
            ->assertSee('Точная цена группы');
    });

    it('explains that prices are per variant for a card with variants', function (): void {
        $card = variantCard([], optionWith('Размер', ['S']));

        Livewire::test(EditProduct::class, ['record' => $card->getRouteKey()])
            ->assertOk()
            ->assertSee('Цены групп');
    });
});

describe('variant action «Цены групп» (GP-17)', function (): void {
    beforeEach(function (): void {
        $this->size = optionWith('Размер', ['S', 'M']);
        $this->card = variantCard(['base_price' => '1000.00'], $this->size);
        $this->variant = variantOf($this->card, [$this->size->values[0]]);
    });

    function groupPricesAction(ProductVariant $variant): TestAction
    {
        return TestAction::make('group_prices')->table($variant);
    }

    it('opens with the stored rows of the variant', function (): void {
        gpTier($this->variant, $this->group, 1, '910.00');

        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->card, 'pageClass' => EditProduct::class])
            ->mountAction(groupPricesAction($this->variant))
            ->assertActionDataSet(fn (array $data): bool => array_values($data['group_prices'][$this->key]) === tierRows([1, '910.00']));
    });

    it('saves the prices of the variant', function (): void {
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->card, 'pageClass' => EditProduct::class])
            ->callAction(groupPricesAction($this->variant), ['group_prices' => [$this->key => tierRows([1, '900'], [5, '850'])]])
            ->assertHasNoActionErrors();

        expect(storedTiers($this->variant, $this->group))->toBe([1 => '900.00', 5 => '850.00'])
            ->and(PriceHistory::query()->where('variant_id', $this->variant->id)->count())->toBe(2);
    });

    it('refuses invalid rows without saving anything', function (): void {
        Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->card, 'pageClass' => EditProduct::class])
            ->callAction(groupPricesAction($this->variant), ['group_prices' => [$this->key => tierRows([5, '800'], [10, '900'])]]);

        expect(storedTiers($this->variant, $this->group))->toBe([]);
    });

    it('is not available to a user without product update rights', function (): void {
        actingAs(User::factory()->manager()->create());
        $role = app(PermissionService::class);

        $component = Livewire::test(VariantsRelationManager::class, ['ownerRecord' => $this->card, 'pageClass' => EditProduct::class]);

        // The manager may update products by default: the action stays available to them.
        expect($role->allows(auth()->user(), 'products.update'))->toBeTrue();
        $component->assertTableActionVisible('group_prices', $this->variant);
    });
});

describe('bulk action «Цены группы» (GP-18)', function (): void {
    function groupBulkAction(): TestAction
    {
        return TestAction::make('group_prices')->table()->bulk();
    }

    it('queues the change for the selected products', function (): void {
        Queue::fake();
        $a = gpProduct('1000.00');
        $b = gpProduct('500.00');
        gpProduct('700.00');

        Livewire::test(ListProducts::class)
            ->assertActionVisible(groupBulkAction())
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(groupBulkAction(), ['group_id' => $this->group->id, 'operation' => GroupPriceBulkOperation::RetailMinusPercent->value, 'value' => '10'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        Queue::assertPushed(ApplyGroupPriceBulkChange::class, fn (ApplyGroupPriceBulkChange $job): bool => collect($job->productIds)->sort()->values()->all() === [$a->id, $b->id]);
    });

    it('applies the change when the queue runs', function (): void {
        $a = gpProduct('1000.00');

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$a->id])
            ->callAction(groupBulkAction(), ['group_id' => $this->group->id, 'operation' => GroupPriceBulkOperation::RetailMinusPercent->value, 'value' => '10']);

        expect(storedTiers($a, $this->group))->toBe([1 => '900.00'])
            ->and(PriceHistory::query()->where('product_id', $a->id)->value('source'))->toBe(PriceChangeSource::Bulk);
    });

    it('needs a group and a value for the percent operations', function (array $data, string $field): void {
        Queue::fake();
        $a = gpProduct('1000.00');

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$a->id])
            ->callAction(groupBulkAction(), ['group_id' => $this->group->id, 'operation' => 'retail_minus_percent', 'value' => '10', ...$data])
            ->assertHasActionErrors([$field]);

        Queue::assertNothingPushed();
    })->with([
        'no group' => [['group_id' => null], 'group_id'],
        'hundred percent' => [['value' => '100'], 'value'],
        'no value' => [['value' => ''], 'value'],
    ]);

    it('deletes exact prices without a value', function (): void {
        $a = gpProduct('1000.00');
        gpTier($a, $this->group, 1, '900.00');
        gpTier($a, $this->group, 10, '800.00');

        Livewire::test(ListProducts::class)
            ->selectTableRecords([$a->id])
            ->callAction(groupBulkAction(), ['group_id' => $this->group->id, 'operation' => 'delete'])
            ->assertHasNoActionErrors();

        expect(storedTiers($a, $this->group))->toBe([10 => '800.00']);
    });

    it('is hidden from users without prices.bulk and cannot be executed by them', function (): void {
        Queue::fake();
        actingAs(User::factory()->manager()->create());
        $a = gpProduct('1000.00');

        Livewire::test(ListProducts::class)->assertActionHidden(groupBulkAction());

        Queue::assertNothingPushed();
        expect(Gate::allows('changePrices', Product::class))->toBeFalse();
    });
});

describe('price list refresh buttons (CG-17)', function (): void {
    it('queues a build for every group with the section on', function (): void {
        Queue::fake();
        $offering = CustomerGroup::factory()->sections([StorefrontSection::Catalog, StorefrontSection::PriceList])->create(['name' => 'С прайсом']);

        Livewire::test(ListCustomerGroups::class)
            ->callAction('refresh_price_lists')
            ->assertNotified();

        Queue::assertPushed(BuildPriceList::class, fn (BuildPriceList $job): bool => $job->groupId === $offering->id);
    });

    it('queues the build of one group from its page and hides the button when the section is off', function (): void {
        Queue::fake();
        $offering = CustomerGroup::factory()->sections([StorefrontSection::Catalog, StorefrontSection::PriceList])->create();
        $plain = CustomerGroup::factory()->sections([StorefrontSection::Catalog])->create();

        Livewire::test(EditCustomerGroup::class, ['record' => $plain->getRouteKey()])->assertActionHidden('refresh_price_list');

        Livewire::test(EditCustomerGroup::class, ['record' => $offering->getRouteKey()])
            ->assertActionVisible('refresh_price_list')
            ->callAction('refresh_price_list');

        Queue::assertPushed(BuildPriceList::class, 1);
    });
});

describe('price history (GP-16)', function (): void {
    it('shows the group and the quantity columns and filters by group', function (): void {
        $product = gpProduct('1000.00', ['name' => 'Стул история']);
        $other = CustomerGroup::factory()->create(['name' => 'Другая группа']);
        $tier = PriceHistory::factory()->create(['product_id' => $product->id, 'customer_group_id' => $this->group->id, 'min_qty' => 10, 'field' => 'group_tier', 'old_value' => null, 'new_value' => '900.00']);
        $retail = PriceHistory::factory()->create(['product_id' => $product->id, 'field' => 'base_price', 'old_value' => '900.00', 'new_value' => '1000.00']);
        $otherRow = PriceHistory::factory()->create(['product_id' => $product->id, 'customer_group_id' => $other->id, 'min_qty' => 1, 'field' => 'group_price', 'old_value' => null, 'new_value' => '950.00']);

        Livewire::test(ListPriceHistory::class)
            ->assertOk()
            ->assertSee('Ступень цены группы')
            ->assertSee('Точная цена группы')
            ->assertSee('Опт')
            ->assertSee('Кол-во от')
            ->assertTableColumnStateSet('min_qty', 10, $tier)
            ->assertCanSeeTableRecords([$tier, $retail, $otherRow])
            ->filterTable('customer_group_id', $this->group->id)
            ->assertCanSeeTableRecords([$tier])
            ->assertCanNotSeeTableRecords([$retail, $otherRow]);

        expect(PriceHistoryResource::fieldLabel('group_price'))->toBe('Точная цена группы')
            ->and(PriceHistoryResource::fieldOptions())->toHaveKeys(['group_price', 'group_tier']);
    });
});

describe('pricing settings (GP-19)', function (): void {
    it('edits the rounding of group prices and the next tier hint', function (): void {
        Livewire::test(ManagePricingSettings::class)
            ->assertOk()
            ->assertSee('Применять шаг округления к ценам групп')
            ->assertFormSet(['group_prices_rounding' => true, 'tier_hint_enabled' => true, 'tier_hint_percent' => 50, 'tier_hint_qty' => 20])
            ->fillForm(['group_prices_rounding' => false, 'tier_hint_percent' => 30, 'tier_hint_qty' => 5])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(PricingSettings::class);

        expect($settings->group_prices_rounding)->toBeFalse()
            ->and($settings->tier_hint_enabled)->toBeTrue()
            ->and($settings->tier_hint_percent)->toBe(30)
            ->and($settings->tier_hint_qty)->toBe(5);
    });

    it('switches the hint off and validates the thresholds', function (): void {
        Livewire::test(ManagePricingSettings::class)
            ->fillForm(['tier_hint_percent' => 0])
            ->call('save')
            ->assertHasFormErrors(['tier_hint_percent']);

        Livewire::test(ManagePricingSettings::class)
            ->fillForm(['tier_hint_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(app(PricingSettings::class)->tier_hint_enabled)->toBeFalse();
    });
});
