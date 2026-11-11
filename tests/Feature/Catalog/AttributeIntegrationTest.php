<?php

declare(strict_types=1);

use App\Enums\CheckStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Settings\ManageCatalogSettings;
use App\Filament\Widgets\AttributeMigrationWidget;
use App\Models\Attribute;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\User;
use App\Services\Catalog\AttributeMigrationState;
use App\Services\Catalog\ProductDuplicator;
use App\Services\System\CheckResult;
use App\Services\System\EnvironmentChecker;
use App\Settings\CatalogSettings;
use Livewire\Livewire;

/**
 * FL-04 notices (doctor, dashboard), the settings page «Каталог и фильтры» and product duplication (20.6).
 */
function attrDoctorCheck(): CheckResult
{
    return collect(app(EnvironmentChecker::class)->run())->firstWhere('key', 'attribute_migration');
}

describe('shop:doctor', function (): void {
    it('is fine when no product has JSON characteristics', function (): void {
        Product::factory()->create(['attributes' => null]);

        expect(attrDoctorCheck()->status)->toBe(CheckStatus::Ok)
            ->and(attrDoctorCheck()->value)->toBe('не требуется');
    });

    it('warns while products have JSON characteristics and the migration is not finished', function (): void {
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);

        expect(attrDoctorCheck()->status)->toBe(CheckStatus::Warning)
            ->and(attrDoctorCheck()->value)->toContain('Миграция характеристик не завершена')
            ->and(attrDoctorCheck()->hint)->toContain('shop:attributes-migrate');

        $this->artisan('shop:doctor')->expectsOutputToContain('Миграция характеристик');
    });

    it('shows the progress of a running migration', function (): void {
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);
        app(AttributeMigrationState::class)->begin(10, 0);
        app(AttributeMigrationState::class)->advance(5, 4, 0);

        expect(attrDoctorCheck()->value)->toContain('Перенесено 4 из 10');
    });

    it('is fine after the migration finished', function (): void {
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);
        attrMigrated();

        expect(attrDoctorCheck()->status)->toBe(CheckStatus::Ok);
    });

    it('is fine after the real migration', function (): void {
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);
        $this->artisan('shop:attributes-migrate')->assertSuccessful();

        expect(attrDoctorCheck()->status)->toBe(CheckStatus::Ok)->and(attrDoctorCheck()->value)->toBe('Миграция характеристик завершена');
    });
});

describe('dashboard notice', function (): void {
    it('shows «Миграция характеристик не завершена» to staff who may edit attributes', function (): void {
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);
        $this->actingAs(User::factory()->admin()->create());

        expect(AttributeMigrationWidget::canView())->toBeTrue();

        Livewire::test(AttributeMigrationWidget::class)->assertSee('Миграция характеристик не завершена');
        $this->get(Dashboard::getUrl())->assertOk()->assertSee('Миграция характеристик не завершена');
    });

    it('shows the progress while the job runs', function (): void {
        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);
        app(AttributeMigrationState::class)->begin(10, 0);
        app(AttributeMigrationState::class)->advance(5, 4, 0);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(AttributeMigrationWidget::class)->assertSee('Перенесено 4 из 10 товаров');
    });

    it('is hidden when nothing is pending, after the migration and without the right', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        expect(AttributeMigrationWidget::canView())->toBeFalse();

        Product::factory()->create(['attributes' => [['name' => 'Цвет', 'value' => 'Красный']]]);
        expect(AttributeMigrationWidget::canView())->toBeTrue();

        $this->actingAs(staffWith(['dashboard.view']));
        expect(AttributeMigrationWidget::canView())->toBeFalse();

        $this->actingAs(User::factory()->admin()->create());
        attrMigrated();
        expect(AttributeMigrationWidget::canView())->toBeFalse();
    });
});

describe('product duplication (20.6)', function (): void {
    it('copies the normalized values together with the JSON', function (): void {
        $color = Attribute::factory()->create(['name' => 'Цвет']);
        $power = Attribute::factory()->number('Вт')->create(['name' => 'Мощность']);
        $source = Product::factory()->create(['attributes' => null]);
        attrService()->setProductValues($source, [['attribute_id' => $color->id, 'values' => ['Красный', 'Синий']], ['attribute_id' => $power->id, 'values' => '12.5']]);

        $copy = app(ProductDuplicator::class)->duplicate($source->fresh());

        expect($copy->id)->not->toBe($source->id)
            ->and(ProductAttributeValue::query()->where('product_id', $copy->id)->count())->toBe(3)
            ->and(ProductAttributeValue::query()->where('product_id', $copy->id)->where('attribute_id', $power->id)->value('value_number'))->toEqual('12.5000')
            ->and($copy->fresh()->specs())->toBe($source->fresh()->specs())
            // The source keeps its own rows.
            ->and(ProductAttributeValue::query()->where('product_id', $source->id)->count())->toBe(3);
    });

    it('still copies the JSON of a product that was not migrated yet', function (): void {
        $source = Product::factory()->create(['attributes' => [['name' => 'Материал', 'value' => 'Дерево']]]);

        $copy = app(ProductDuplicator::class)->duplicate($source);

        expect($copy->fresh()->specs())->toBe([['name' => 'Материал', 'value' => 'Дерево']])
            ->and(ProductAttributeValue::query()->count())->toBe(0);
    });
});

describe('settings page «Каталог и фильтры»', function (): void {
    it('renders for an administrator with the current values and without the migration flag', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageCatalogSettings::class)
            ->assertOk()
            ->assertFormSet(['filters_max_per_category' => 9, 'facets_cache_minutes' => 10, 'seo_single_value_min_products' => 3, 'create_attributes_on_import' => true])
            ->assertSee('Каталог и фильтры');
    });

    it('saves the settings and keeps the migration flag', function (): void {
        $this->actingAs(User::factory()->admin()->create());
        attrMigrated();
        $flag = app(CatalogSettings::class)->attributes_migrated_at;

        Livewire::test(ManageCatalogSettings::class)
            ->fillForm(['filters_max_per_category' => 5, 'facets_cache_minutes' => 30, 'seo_single_value_min_products' => 7, 'create_attributes_on_import' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(CatalogSettings::class);

        expect($settings->filters_max_per_category)->toBe(5)
            ->and($settings->facets_cache_minutes)->toBe(30)
            ->and($settings->seo_single_value_min_products)->toBe(7)
            ->and($settings->create_attributes_on_import)->toBeFalse()
            ->and($settings->attributes_migrated_at)->toBe($flag);
    });

    it('validates the numbers (at most 9 filters)', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(ManageCatalogSettings::class)
            ->fillForm(['filters_max_per_category' => 10, 'facets_cache_minutes' => 0])
            ->call('save')
            ->assertHasFormErrors(['filters_max_per_category', 'facets_cache_minutes']);
    });

    it('follows the permissions settings.catalog.view / update', function (): void {
        $this->actingAs(staffWith(['settings.catalog.view']));
        expect(ManageCatalogSettings::canAccess())->toBeTrue();
        expect(Livewire::test(ManageCatalogSettings::class)->instance()->canEdit())->toBeFalse();

        $this->actingAs(staffWith(['dashboard.view']));
        expect(ManageCatalogSettings::canAccess())->toBeFalse();

        $this->actingAs(User::factory()->manager()->create());
        expect(ManageCatalogSettings::canAccess())->toBeFalse();
    });

    it('is listed in the settings navigation group', function (): void {
        $this->actingAs(User::factory()->admin()->create());

        expect(ManageCatalogSettings::getNavigationLabel())->toBe('Каталог и фильтры')
            ->and(ManageCatalogSettings::getNavigationGroup())->toBe('Настройки');
    });
});
