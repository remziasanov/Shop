<?php

declare(strict_types=1);

use App\Enums\SalesMode;
use App\Services\Sales\AvailabilityService;

function actions($product)
{
    return app(AvailabilityService::class)->actionsFor($product->fresh());
}

beforeEach(function () {
    sales(['stock_tracking' => true, 'allow_backorder' => false]);
});

it('allows nothing but shows the price in showcase mode', function () {
    $a = actions(sellable(['sales_mode' => SalesMode::Showcase]));

    expect($a->mode)->toBe(SalesMode::Showcase)
        ->and([$a->canAddToCart, $a->canReserve, $a->canInquire, $a->outOfStock])->toBe([false, false, false, false])
        ->and($a->showPrice)->toBeTrue();
});

it('offers only an inquiry for «price on request» and hides the price', function () {
    $a = actions(sellable(['price_on_request' => true, 'sales_mode' => SalesMode::Cart]));

    expect($a->mode)->toBe(SalesMode::Inquiry)
        ->and([$a->canAddToCart, $a->canReserve, $a->canInquire])->toBe([false, false, true])
        ->and($a->showPrice)->toBeFalse();
});

it('offers an inquiry in inquiry mode regardless of stock', function () {
    $a = actions(sellable(['sales_mode' => SalesMode::Inquiry, 'stock_qty' => 0]));

    expect($a->canInquire)->toBeTrue()->and($a->outOfStock)->toBeFalse();
});

it('has no limits when the product stock is not tracked', function () {
    $a = actions(sellable(['stock_qty' => null]));

    expect([$a->canAddToCart, $a->canReserve, $a->maxQty, $a->maxReserveQty, $a->outOfStock])->toBe([true, true, null, null, false]);
});

it('has no limits when stock tracking is off in the shop', function () {
    sales(['stock_tracking' => false]);
    $a = actions(sellable(['stock_qty' => 0]));

    expect([$a->canAddToCart, $a->canReserve, $a->maxQty, $a->outOfStock])->toBe([true, true, null, false]);
});

it('limits quantity to the available stock', function () {
    $a = actions(sellable(['stock_qty' => 10, 'reserved_qty' => 4]));

    expect([$a->canAddToCart, $a->canReserve, $a->maxQty, $a->maxReserveQty, $a->backorder])->toBe([true, true, 6, 6, false]);
});

it('does not limit the cart with backorder but still limits a reservation', function () {
    sales(['allow_backorder' => true]);
    $a = actions(sellable(['stock_qty' => 4]));

    expect($a->maxQty)->toBeNull()->and($a->maxReserveQty)->toBe(4)->and($a->canAddToCart)->toBeTrue();
});

it('switches the cart to backorder and disables reservation when nothing is left', function () {
    sales(['allow_backorder' => true]);
    $a = actions(sellable(['stock_qty' => 2, 'reserved_qty' => 2]));

    expect([$a->canAddToCart, $a->canReserve, $a->backorder, $a->outOfStock, $a->canInquire])->toBe([true, false, true, false, false]);
});

it('disables cart and reservation and offers an inquiry when out of stock without backorder', function () {
    $a = actions(sellable(['stock_qty' => 0]));

    expect([$a->canAddToCart, $a->canReserve, $a->backorder, $a->outOfStock, $a->canInquire, $a->maxQty])->toBe([false, false, false, true, true, 0]);
});

it('treats a reservation-only product with backorder as out of stock', function () {
    sales(['allow_backorder' => true]);
    $a = actions(sellable(['sales_mode' => SalesMode::Reservation, 'stock_qty' => 0]));

    expect($a->outOfStock)->toBeTrue()->and($a->canReserve)->toBeFalse()->and($a->backorder)->toBeFalse();
});

it('exposes which buttons the mode has', function () {
    $a = actions(sellable(['sales_mode' => SalesMode::Reservation]));

    expect($a->hasCartButton())->toBeFalse()->and($a->hasReservationButton())->toBeTrue();
});
