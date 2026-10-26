<?php

declare(strict_types=1);

use App\Enums\PriceChangeSource;
use App\Models\Import;
use App\Models\PriceHistory;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\User;
use App\Services\Pricing\PriceChangeContext;
use App\Services\Pricing\ProductPriceUpdater;

it('records nothing when a product is created', function (): void {
    Product::factory()->create(['base_price' => '1000.00']);

    expect(PriceHistory::query()->count())->toBe(0);
});

it('records a manual change with the current user', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);
    $user = User::factory()->create();
    $this->actingAs($user);

    $product->update(['base_price' => '1200.50']);

    $row = PriceHistory::query()->sole();

    expect($row->product_id)->toBe($product->id)
        ->and($row->field)->toBe('base_price')
        ->and($row->old_value)->toBe('1000.00')
        ->and($row->new_value)->toBe('1200.50')
        ->and($row->source)->toBe(PriceChangeSource::Manual)
        ->and($row->user_id)->toBe($user->id)
        ->and($row->import_id)->toBeNull()
        ->and($row->created_at)->not->toBeNull()
        ->and($row->product->is($product))->toBeTrue()
        ->and($row->user->is($user))->toBeTrue();
});

it('records every changed price field with null transitions', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);

    $product->update(['cost_price' => '600.00', 'fixed_price' => '900.00', 'compare_at_price' => '1500.00']);
    $product->update(['fixed_price' => null]);

    $rows = PriceHistory::query()->orderBy('id')->get();

    expect($rows->pluck('field')->all())->toBe(['cost_price', 'fixed_price', 'compare_at_price', 'fixed_price'])
        ->and($rows[0]->old_value)->toBeNull()
        ->and($rows[0]->new_value)->toBe('600.00')
        ->and($rows[3]->old_value)->toBe('900.00')
        ->and($rows[3]->new_value)->toBeNull()
        ->and($rows[0]->user_id)->toBeNull();
});

it('records nothing when the value is unchanged', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);

    $product->update(['base_price' => '1000']);
    $product->update(['base_price' => '1000.00', 'name' => 'Новое название']);

    expect(PriceHistory::query()->count())->toBe(0);
});

it('does not record final price changes made by rules', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);
    PriceRule::factory()->markupPercent(10)->create();

    $product->update(['name' => 'Другое имя', 'is_featured' => true]);
    app(ProductPriceUpdater::class)->recalculate([$product->id]);

    expect($product->fresh()->final_price)->toBe('1100.00')
        ->and(PriceHistory::query()->count())->toBe(0);
});

it('uses the source and import id of the running context', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);
    $user = User::factory()->create();
    $this->actingAs($user);
    $context = app(PriceChangeContext::class);
    $importId = Import::factory()->create()->id;

    $context->run(PriceChangeSource::Bulk, null, fn () => $product->update(['base_price' => '1100.00']));
    $context->run(PriceChangeSource::Import, $importId, fn () => $product->update(['base_price' => '1200.00']), userId: null);
    $product->update(['base_price' => '1300.00']);

    $rows = PriceHistory::query()->orderBy('id')->get();

    expect($rows->pluck('source')->all())->toBe([PriceChangeSource::Bulk, PriceChangeSource::Import, PriceChangeSource::Manual])
        ->and($rows->pluck('import_id')->all())->toBe([null, $importId, null])
        ->and($rows->pluck('user_id')->all())->toBe([$user->id, $user->id, $user->id]);
});

it('restores the previous context after the callback, also on exceptions', function (): void {
    $context = app(PriceChangeContext::class);

    expect(fn () => $context->run(PriceChangeSource::Bulk, null, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect($context->source())->toBe(PriceChangeSource::Manual);

    $result = $context->run(PriceChangeSource::Import, 5, function () use ($context): array {
        return $context->run(PriceChangeSource::Bulk, null, fn (): array => [$context->source(), $context->importId()]);
    }, 9);

    expect($result)->toBe([PriceChangeSource::Bulk, null])
        ->and($context->source())->toBe(PriceChangeSource::Manual)
        ->and($context->importId())->toBeNull()
        ->and($context->userId())->toBeNull();
});

it('lets an explicit user override the authenticated one', function (): void {
    $product = Product::factory()->create(['base_price' => '1000.00']);
    $actor = User::factory()->create();
    $this->actingAs(User::factory()->create());

    app(PriceChangeContext::class)->run(PriceChangeSource::Bulk, null, fn () => $product->update(['base_price' => '1100.00']), $actor->id);

    expect(PriceHistory::query()->sole()->user_id)->toBe($actor->id);
});

it('keeps history rows when the user is deleted and removes them with the product', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create();
    PriceHistory::factory()->create(['product_id' => $product->id, 'user_id' => $user->id]);

    $user->delete();
    expect(PriceHistory::query()->sole()->user_id)->toBeNull();

    $product->forceDelete();
    expect(PriceHistory::query()->count())->toBe(0);
});
