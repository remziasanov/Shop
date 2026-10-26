<?php

declare(strict_types=1);

use App\Services\Pricing\ProductPriceUpdater;

beforeEach(function (): void {
    $this->updater = new ProductPriceUpdater;
});

it('uses the base price when no fixed price is set', function (): void {
    expect($this->updater->calculate('1290.50', null, null))->toBe(['final_price' => '1290.50', 'display_old_price' => null]);
});

it('prefers the fixed price over the base price', function (): void {
    expect($this->updater->calculate('1000.00', '850.00', null))->toBe(['final_price' => '850.00', 'display_old_price' => null]);
});

it('shows compare_at_price as the old price only when greater than the final price', function (): void {
    expect($this->updater->calculate('1000.00', null, '1500.00'))->toBe(['final_price' => '1000.00', 'display_old_price' => '1500.00'])
        ->and($this->updater->calculate('1000.00', null, '1000.00')['display_old_price'])->toBeNull()
        ->and($this->updater->calculate('1000.00', null, '900.00')['display_old_price'])->toBeNull()
        ->and($this->updater->calculate('1000.00', '700.00', '900.00')['display_old_price'])->toBe('900.00');
});

it('does not suffer from float errors', function (): void {
    expect($this->updater->calculate('0.10', null, '0.30')['display_old_price'])->toBe('0.30')
        ->and($this->updater->calculate('19999999999.99', null, null)['final_price'])->toBe('19999999999.99');
});
