<?php

declare(strict_types=1);

use App\Enums\AgeGateMode;
use App\Enums\AgeRestriction;
use App\Filament\Pages\Settings\ManageAgeGateSettings;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Age\AgeGate;
use App\Services\Age\AgeRestrictionResolver;
use App\Settings\AgeGateSettings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('saves the age restriction on the category form and inherits it on the fly (AG-12)', function () {
    $parent = Category::factory()->create(['name' => 'Мебель']);

    Livewire::test(CreateCategory::class)
        ->fillForm(['name' => 'Табак', 'is_active' => true, 'sort_order' => 0, 'age_restriction' => '18'])
        ->call('create')
        ->assertHasNoFormErrors();

    $tobacco = Category::query()->where('name', 'Табак')->firstOrFail();
    expect($tobacco->age_restriction)->toBe(AgeRestriction::Age18);

    $child = Category::factory()->create(['name' => 'Трубочный', 'parent_id' => $tobacco->id]);
    $product = Product::factory()->inCategory($child)->create();

    // Only the category row holds the value; the others are not rewritten.
    expect($child->fresh()->age_restriction)->toBeNull()
        ->and($product->fresh()->age_restriction)->toBeNull()
        ->and(app(AgeRestrictionResolver::class)->forProduct($product))->toBe(AgeRestriction::Age18);

    Livewire::test(EditCategory::class, ['record' => $parent->getRouteKey()])->assertOk()->assertFormFieldExists('age_restriction');
});

it('clears the category restriction when «Нет» is chosen (AG-12)', function () {
    $category = Category::factory()->create(['age_restriction' => AgeRestriction::Age16]);

    Livewire::test(EditCategory::class, ['record' => $category->getRouteKey()])
        ->fillForm(['age_restriction' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($category->fresh()->age_restriction)->toBeNull();
});

it('saves the own age restriction of a product on the main tab and hints the effective one (AG-12)', function () {
    $category = Category::factory()->create(['name' => 'Табак', 'age_restriction' => AgeRestriction::Age18]);
    $product = Product::factory()->inCategory($category)->create();

    $form = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertOk()
        ->assertFormFieldExists('age_restriction')
        ->assertSee('Действует: 18+ (из категории «Табак»)');

    $form->fillForm(['age_restriction' => '16'])->call('save')->assertHasNoFormErrors();

    expect($product->fresh()->age_restriction)->toBe(AgeRestriction::Age16);
});

it('shows the effective level as a badge, filters and bulk-sets it in the product list (AG-12)', function () {
    $tobacco = Category::factory()->create(['name' => 'Табак', 'age_restriction' => AgeRestriction::Age18]);
    $plain = Category::factory()->create(['name' => 'Мебель']);
    $inherited = Product::factory()->inCategory($tobacco)->create();
    $own = Product::factory()->inCategory($plain)->create(['age_restriction' => AgeRestriction::Age16]);
    $none = Product::factory()->inCategory($plain)->create();

    $list = Livewire::test(ListProducts::class)->assertCanSeeTableRecords([$inherited, $own, $none]);

    $list->assertTableColumnStateSet('age_restriction', '18+', $inherited)
        ->assertTableColumnStateSet('age_restriction', '16+', $own)
        ->assertTableColumnStateSet('age_restriction', null, $none);

    $list->filterTable('age_restriction', 'inherited')->assertCanSeeTableRecords([$inherited])->assertCanNotSeeTableRecords([$own, $none]);
    $list->filterTable('age_restriction', '16')->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$inherited, $none]);
    $list->filterTable('age_restriction', 'none')->assertCanSeeTableRecords([$none])->assertCanNotSeeTableRecords([$inherited, $own]);
    $list->filterTable('age_restriction', 'any')->assertCanSeeTableRecords([$inherited, $own])->assertCanNotSeeTableRecords([$none]);

    $list->removeTableFilter('age_restriction')
        ->selectTableRecords([$none->id])
        ->callAction(TestAction::make('set_age')->table()->bulk(), ['age_restriction' => '18']);

    expect($none->fresh()->age_restriction)->toBe(AgeRestriction::Age18);
});

it('drops the cached «has restricted products» flag after a bulk change (AG-03)', function () {
    ageGate();
    $product = Product::factory()->inCategory(Category::factory()->create())->create();

    expect(app(AgeGate::class)->hasRestricted())->toBeFalse()->and(Cache::has(AgeGate::CACHE_KEY))->toBeTrue();

    Livewire::test(ListProducts::class)
        ->selectTableRecords([$product->id])
        ->callAction(TestAction::make('set_age')->table()->bulk(), ['age_restriction' => '16']);

    expect(app(AgeGate::class)->hasRestricted())->toBeTrue();
});

it('renders the settings page with the preview and the counters (AG-14)', function () {
    $tobacco = Category::factory()->create(['name' => 'Табак', 'age_restriction' => AgeRestriction::Age18]);
    Product::factory()->count(2)->inCategory($tobacco)->create();
    Product::factory()->inCategory(Category::factory()->create())->create(['age_restriction' => AgeRestriction::Age16]);
    Product::factory()->inCategory(Category::factory()->create())->create();

    Livewire::test(ManageAgeGateSettings::class)
        ->assertOk()
        ->assertSee('Предпросмотр плашки')
        ->assertSee('Вам уже исполнилось 18 лет?')
        ->assertSeeHtml('data-count="products"')
        ->assertSeeText('Товаров с ограничением');

    expect(app(AgeRestrictionResolver::class)->counts())->toBe([
        'products_own' => 1,
        'products_effective' => 3,
        'categories_own' => 1,
        'categories_effective' => 1,
    ]);
});

it('saves the age gate settings and validates them (AG-02)', function () {
    Livewire::test(ManageAgeGateSettings::class)
        ->fillForm([
            'enabled' => true,
            'mode' => 'restricted',
            'site_min_age' => 16,
            'remember_days' => 90,
            'title' => 'Вам {age}?',
            'text' => 'Только с {age}.',
            'yes_label' => 'Да',
            'no_label' => 'Нет',
            'decline_message' => 'Увы.',
            'show_badge' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = app(AgeGateSettings::class);
    $settings->refresh();

    expect($settings->enabled)->toBeTrue()
        ->and($settings->mode)->toBe(AgeGateMode::Restricted)
        ->and($settings->site_min_age)->toBe(16)
        ->and($settings->remember_days)->toBe(90)
        ->and($settings->show_badge)->toBeFalse();

    Livewire::test(ManageAgeGateSettings::class)
        ->fillForm(['remember_days' => 400, 'title' => ''])
        ->call('save')
        ->assertHasFormErrors(['remember_days' => 'max', 'title' => 'required']);
});

it('has disabled defaults after the settings migration (TZ 16.4)', function () {
    $settings = app(AgeGateSettings::class);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->mode)->toBe(AgeGateMode::Site)
        ->and($settings->site_min_age)->toBe(18)
        ->and($settings->remember_days)->toBe(30);
});
