<?php

declare(strict_types=1);

use App\Enums\OptionDisplayType;
use App\Exceptions\OptionException;
use App\Filament\Resources\OptionTypes\OptionTypeResource;
use App\Filament\Resources\OptionTypes\Pages\CreateOptionType;
use App\Filament\Resources\OptionTypes\Pages\EditOptionType;
use App\Filament\Resources\OptionTypes\Pages\ListOptionTypes;
use App\Filament\Resources\OptionTypes\RelationManagers\ValuesRelationManager;
use App\Models\OptionType;
use App\Models\OptionValue;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Variants\OptionService;
use App\Services\Variants\VariantService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
});

describe('permissions (VR-26, 25.4)', function (): void {
    it('lets admin and manager open the pages', function (string $role): void {
        actingAs(User::factory()->{$role}()->create());
        $type = OptionType::factory()->create();

        $this->get(OptionTypeResource::getUrl('index'))->assertOk();
        $this->get(OptionTypeResource::getUrl('create'))->assertOk();
        $this->get(OptionTypeResource::getUrl('edit', ['record' => $type]))->assertOk();
    })->with(['admin', 'manager']);

    it('answers 403 without option_types.* permissions', function (): void {
        actingAs(staffWith(['dashboard.view', 'products.view']));
        $type = OptionType::factory()->create();

        $this->get(OptionTypeResource::getUrl('index'))->assertForbidden();
        $this->get(OptionTypeResource::getUrl('create'))->assertForbidden();
        $this->get(OptionTypeResource::getUrl('edit', ['record' => $type]))->assertForbidden();
    });

    it('lets a viewer see the list but not create or edit', function (): void {
        actingAs(staffWith(['dashboard.view', 'option_types.view']));
        $type = OptionType::factory()->create();

        $this->get(OptionTypeResource::getUrl('index'))->assertOk();
        $this->get(OptionTypeResource::getUrl('create'))->assertForbidden();
        $this->get(OptionTypeResource::getUrl('edit', ['record' => $type]))->assertForbidden();
    });

    it('hides the value actions from a user who may not update options', function (): void {
        actingAs(staffWith(['dashboard.view', 'option_types.view']));
        $type = optionWith('Размер', ['S']);

        Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditOptionType::class])
            ->assertActionHidden(TestAction::make('create')->table())
            ->assertTableActionHidden('delete', $type->values->first())
            ->assertTableActionHidden('merge', $type->values->first());
    });
});

describe('options CRUD (VR-01, VR-26)', function (): void {
    beforeEach(fn () => actingAs(User::factory()->admin()->create()));

    it('lists options with the number of values', function (): void {
        $type = optionWith('Размер', ['S', 'M']);

        Livewire::test(ListOptionTypes::class)
            ->assertCanSeeTableRecords([$type])
            ->assertTableColumnStateSet('values_count', 2, $type);
    });

    it('creates an option with a generated slug and the next order', function (): void {
        OptionType::factory()->create(['sort_order' => 30]);

        Livewire::test(CreateOptionType::class)
            ->fillForm(['name' => 'Размер обуви', 'display_type' => 'button', 'is_filterable' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $type = OptionType::query()->where('name', 'Размер обуви')->firstOrFail();

        expect($type->display_type)->toBe(OptionDisplayType::Button)
            ->and($type->is_filterable)->toBeTrue()
            ->and($type->slug)->not->toBe('')
            ->and($type->sort_order)->toBe(40);
    });

    it('updates an option', function (): void {
        $type = OptionType::factory()->create(['name' => 'Цвет']);

        Livewire::test(EditOptionType::class, ['record' => $type->getRouteKey()])
            ->fillForm(['name' => 'Расцветка', 'display_type' => 'color', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $type->refresh();
        expect($type->name)->toBe('Расцветка')->and($type->display_type)->toBe(OptionDisplayType::Color)->and($type->is_active)->toBeFalse();
    });

    it('deletes an unused option and refuses a used one with the number of cards', function (): void {
        $unused = OptionType::factory()->create();
        $color = optionWith('Цвет', ['Красный'], color: true);
        $card = variantCard([], $color);
        variantOf($card, [$color->values[0]]);

        Livewire::test(ListOptionTypes::class)
            ->callAction(TestAction::make('delete')->table($color))
            ->assertNotified(__('admin_variants.errors.type_in_use', ['name' => 'Цвет', 'count' => 1]))
            ->callAction(TestAction::make('delete')->table($unused));

        expect(OptionType::query()->find($color->id))->not->toBeNull()
            ->and(OptionType::query()->find($unused->id))->toBeNull();
    });

    it('refuses to delete an option that is an axis even without variants', function (): void {
        $color = optionWith('Цвет', ['Красный']);
        variantCard([], $color);

        expect(fn () => app(OptionService::class)->deleteType($color))->toThrow(OptionException::class);
    });
});

describe('values manager (VR-01, VR-26)', function (): void {
    beforeEach(function (): void {
        actingAs(User::factory()->admin()->create());
        $this->type = optionWith('Цвет', ['Красный', 'Синий'], color: true);
    });

    /** @return Testable */
    function optionValuesManager(OptionType $type)
    {
        return Livewire::test(ValuesRelationManager::class, ['ownerRecord' => $type, 'pageClass' => EditOptionType::class]);
    }

    it('adds a value with a colour and refuses a repeated text and a bad colour', function (): void {
        optionValuesManager($this->type)
            ->callAction(TestAction::make('create')->table(), ['value' => 'Зелёный', 'hex' => '#00FF00'])
            ->assertHasNoFormErrors();

        $green = OptionValue::query()->where('value', 'Зелёный')->firstOrFail();
        expect($green->hex)->toBe('#00ff00')->and($green->slug)->toBe('zelyonyy')->and($green->sort_order)->toBe(11);

        optionValuesManager($this->type)
            ->callAction(TestAction::make('create')->table(), ['value' => ' зелёный '])
            ->assertNotified(__('admin_variants.errors.value_exists', ['value' => 'зелёный']));

        expect(fn () => app(OptionService::class)->createValue($this->type, ['value' => 'Белый', 'hex' => 'red']))->toThrow(OptionException::class);
        expect(OptionValue::query()->where('option_type_id', $this->type->id)->count())->toBe(3);
    });

    it('edits a value', function (): void {
        $red = $this->type->values[0];

        optionValuesManager($this->type)
            ->callAction(TestAction::make('edit')->table($red), ['value' => 'Алый', 'hex' => '#ff0000'])
            ->assertHasNoFormErrors();

        expect($red->refresh()->value)->toBe('Алый')->and($red->hex)->toBe('#ff0000');
    });

    it('reorders values by dragging', function (): void {
        [$red, $blue] = [$this->type->values[0], $this->type->values[1]];

        optionValuesManager($this->type)->call('reorderTable', [(string) $blue->id, (string) $red->id]);

        expect($blue->refresh()->sort_order)->toBeLessThan($red->refresh()->sort_order);
        expect($this->type->values()->pluck('id')->all())->toBe([$blue->id, $red->id]);
    });

    it('stores a swatch image after the NF-11 checks and removes it again', function (): void {
        $red = $this->type->values[0];
        $tmp = OptionService::TEMP_DIRECTORY.'/swatch.png';
        Storage::disk('local')->put($tmp, UploadedFile::fake()->image('s.png', 300, 200)->getContent());

        app(OptionService::class)->updateValue($red, ['swatch' => $tmp]);
        $red->refresh();

        expect($red->image_path)->toStartWith('options/')
            ->and(Storage::disk('public')->exists($red->image_path))->toBeTrue()
            ->and(Storage::disk('local')->exists($tmp))->toBeFalse();

        $path = $red->image_path;
        app(OptionService::class)->updateValue($red, ['remove_swatch' => true]);

        expect($red->refresh()->image_path)->toBeNull()->and(Storage::disk('public')->exists($path))->toBeFalse();
    });

    it('uploads a swatch image through the value form', function (): void {
        optionValuesManager($this->type)
            ->callAction(TestAction::make('create')->table(), ['value' => 'Зелёный', 'hex' => '#00ff00', 'swatch' => UploadedFile::fake()->image('green.png', 300, 300)])
            ->assertHasNoFormErrors();

        $green = OptionValue::query()->where('value', 'Зелёный')->firstOrFail();

        expect($green->image_path)->toStartWith('options/')
            ->and(Storage::disk('public')->exists($green->image_path))->toBeTrue()
            ->and(Storage::disk('local')->allFiles(OptionService::TEMP_DIRECTORY))->toBe([]);
    });

    it('refuses a swatch that is not an image or comes from a foreign path', function (): void {
        $red = $this->type->values[0];
        $tmp = OptionService::TEMP_DIRECTORY.'/bad.png';
        Storage::disk('local')->put($tmp, 'not an image');

        expect(fn () => app(OptionService::class)->updateValue($red, ['swatch' => $tmp]))->toThrow(OptionException::class)
            ->and(fn () => app(OptionService::class)->updateValue($red, ['swatch' => '../.env']))->toThrow(OptionException::class);

        expect($red->refresh()->image_path)->toBeNull();
    });

    it('deletes an unused value and refuses a used one with «используется в N товарах»', function (): void {
        [$red, $blue] = [$this->type->values[0], $this->type->values[1]];
        foreach ([0, 1] as $i) {
            $card = variantCard([], $this->type);
            variantOf($card, [$red]);
        }

        optionValuesManager($this->type)
            ->callAction(TestAction::make('delete')->table($red))
            ->assertNotified(__('admin_variants.errors.value_in_use', ['value' => 'Красный', 'count' => 2]))
            ->callAction(TestAction::make('delete')->table($blue));

        expect(OptionValue::query()->find($red->id))->not->toBeNull()
            ->and(OptionValue::query()->find($blue->id))->toBeNull();
    });

    it('merges a value into another one: variants and photos move, the source goes away', function (): void {
        [$red, $blue] = [$this->type->values[0], $this->type->values[1]];
        $size = optionWith('Размер', ['S']);
        $card = variantCard([], $this->type, $size);
        $variant = variantOf($card, [$red, $size->values[0]]);
        $image = ProductImage::factory()->create(['product_id' => $card->id, 'option_value_id' => $red->id]);

        optionValuesManager($this->type)
            ->callAction(TestAction::make('merge')->table($red), ['target' => $blue->id])
            ->assertNotified();

        expect(OptionValue::query()->find($red->id))->toBeNull()
            ->and($variant->values()->where('option_type_id', $this->type->id)->value('option_value_id'))->toBe($blue->id)
            ->and($variant->refresh()->combo_hash)->toBe(VariantService::comboHash([$blue->id, $size->values[0]->id]))
            ->and($image->refresh()->option_value_id)->toBe($blue->id);
    });

    it('refuses a merge that would create the same combination twice', function (): void {
        [$red, $blue] = [$this->type->values[0], $this->type->values[1]];
        $card = variantCard([], $this->type);
        variantOf($card, [$red]);
        variantOf($card, [$blue]);

        optionValuesManager($this->type)
            ->callAction(TestAction::make('merge')->table($red), ['target' => $blue->id])
            ->assertNotified();

        expect(OptionValue::query()->find($red->id))->not->toBeNull()
            ->and(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(2);
    });
});
