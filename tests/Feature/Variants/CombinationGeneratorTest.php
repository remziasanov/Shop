<?php

declare(strict_types=1);

use App\Exceptions\VariantException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Variants\CombinationGenerator;
use App\Services\Variants\VariantService;
use App\Settings\CatalogSettings;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->generator = app(CombinationGenerator::class);
    $this->color = optionWith('Цвет', ['Красный', 'Синий'], color: true);
    $this->size = optionWith('Размер', ['S', 'M', 'L']);
    $this->card = variantCard(['sku' => 'TEE'], $this->color, $this->size);

    $this->selection = fn (array $colors, array $sizes): array => [
        $this->color->id => array_map(fn (int $i): int => $this->color->values[$i]->id, $colors),
        $this->size->id => array_map(fn (int $i): int => $this->size->values[$i]->id, $sizes),
    ];
});

it('previews «будет создано N, уже есть M» with SKUs from the template (VR-05)', function (): void {
    variantOf($this->card, [$this->color->values[0], $this->size->values[0]]);

    $plan = $this->generator->preview($this->card, ($this->selection)([0, 1], [0, 1, 2]), '{sku}-{v1}-{v2}');

    expect($plan->rows)->toHaveCount(6)
        ->and($plan->toCreateCount())->toBe(5)
        ->and($plan->existingCount())->toBe(1)
        ->and($plan->summary())->toBe('Будет создано 5, уже есть 1')
        ->and($plan->exceedsLimit())->toBeFalse()
        ->and($plan->rows[0]->exists)->toBeTrue()
        ->and($plan->rows[0]->sku)->toBe('TEE-krasnyy-s')
        ->and($plan->rows[1]->sku)->toBe('TEE-krasnyy-m')
        ->and($plan->rows[1]->label)->toBe('Цвет: Красный, Размер: M')
        ->and($plan->rows[5]->sku)->toBe('TEE-siniy-l');
});

it('trims placeholders of absent axes and flags SKUs that are taken', function (): void {
    $oneAxis = variantCard(['sku' => 'CAP'], $this->size);
    Product::factory()->create(['sku' => 'CAP-s']);

    $plan = $this->generator->preview($oneAxis, [$this->size->id => [$this->size->values[0]->id, $this->size->values[1]->id]]);

    expect($plan->rows[0]->sku)->toBe('CAP-s')
        ->and($plan->rows[0]->skuTaken)->toBeTrue()
        ->and($plan->rows[1]->skuTaken)->toBeFalse()
        ->and($plan->hasSkuConflicts())->toBeTrue();
});

it('creates the missing variants from the edited list and rolls the card up once (VR-05)', function (): void {
    variantOf($this->card, [$this->color->values[0], $this->size->values[0]], ['sku' => 'OLD']);
    $plan = $this->generator->preview($this->card, ($this->selection)([0, 1], [0, 1]), '{sku}-{v1}-{v2}');

    $rows = $plan->editableList();
    expect($rows)->toHaveCount(3);
    $rows[0]['sku'] = 'EDITED-SKU';

    $created = $this->generator->create($this->card, $rows, ['stock_qty' => 5, 'fixed_price' => '100']);

    expect($created)->toHaveCount(3)
        ->and($created[0]->sku)->toBe('EDITED-SKU')
        ->and(ProductVariant::query()->where('product_id', $this->card->id)->count())->toBe(4)
        ->and($this->card->fresh()->stock_qty)->toBe(25)
        ->and($this->card->fresh()->final_price)->toBe('100.00');
});

it('skips combinations that already exist when creating', function (): void {
    variantOf($this->card, [$this->color->values[0], $this->size->values[0]]);

    $created = $this->generator->create($this->card, [
        ['values' => [$this->color->values[0]->id, $this->size->values[0]->id], 'sku' => 'IGNORED'],
        ['values' => [$this->color->values[0]->id, $this->size->values[1]->id], 'sku' => 'NEW-1'],
    ]);

    expect($created)->toHaveCount(1)->and($created[0]->sku)->toBe('NEW-1');
});

it('checks the limit before creating anything (VR-02, VR-05)', function (): void {
    $settings = app(CatalogSettings::class);
    $settings->max_variants_per_product = 4;
    $settings->save();

    variantOf($this->card, [$this->color->values[0], $this->size->values[0]]);
    $plan = $this->generator->preview($this->card, ($this->selection)([0, 1], [0, 1, 2]));

    expect($plan->exceedsLimit())->toBeTrue();

    expect(fn () => $this->generator->create($this->card, $plan->editableList()))->toThrow(VariantException::class, 'Слишком много');
    expect(ProductVariant::query()->where('product_id', $this->card->id)->count())->toBe(1);
});

it('refuses taken, empty and repeated SKUs without creating anything', function (): void {
    Product::factory()->create(['sku' => 'BUSY']);
    $values = fn (int $c, int $s): array => [$this->color->values[$c]->id, $this->size->values[$s]->id];

    expect(fn () => $this->generator->create($this->card, [['values' => $values(0, 0), 'sku' => 'BUSY']]))->toThrow(ValidationException::class)
        ->and(fn () => $this->generator->create($this->card, [['values' => $values(0, 0), 'sku' => '']]))->toThrow(ValidationException::class)
        ->and(fn () => $this->generator->create($this->card, [
            ['values' => $values(0, 0), 'sku' => 'SAME'],
            ['values' => $values(0, 1), 'sku' => 'SAME'],
        ]))->toThrow(ValidationException::class);

    expect(ProductVariant::query()->count())->toBe(0);
});

it('creates 100 variants (the default limit) in chunks and refuses the 101st', function (): void {
    $big = optionWith('Артикул-ось', array_map(fn (int $i): string => 'V'.$i, range(1, 101)));
    $card = variantCard(['sku' => 'BIG'], $big);
    $plan = $this->generator->preview($card, [$big->id => $big->values->take(100)->pluck('id')->all()]);

    $this->generator->create($card, $plan->editableList(), ['stock_qty' => 1]);

    expect(ProductVariant::query()->where('product_id', $card->id)->count())->toBe(100)
        ->and($card->fresh()->stock_qty)->toBe(100);

    expect(fn () => app(VariantService::class)->create($card, ['values' => [$big->values[100]->id], 'sku' => 'BIG-101']))
        ->toThrow(VariantException::class);
});

it('derives the combination hash from the sorted value ids (VR-04)', function (): void {
    expect(VariantService::comboHash([5, 3]))->toBe(sha1('3,5'))->toBe(VariantService::comboHash([3, 5]));
});
