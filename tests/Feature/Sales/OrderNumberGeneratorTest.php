<?php

declare(strict_types=1);

use App\Enums\OrderType;
use App\Services\Orders\OrderNumberGenerator;

it('numbers requests sequentially per type with prefixes from settings (D-045)', function () {
    $generator = app(OrderNumberGenerator::class);

    expect($generator->next(OrderType::Order))->toBe('Z-00001')
        ->and($generator->next(OrderType::Order))->toBe('Z-00002')
        ->and($generator->next(OrderType::Reservation))->toBe('R-00001')
        ->and($generator->next(OrderType::Inquiry))->toBe('Q-00001')
        ->and($generator->next(OrderType::Order))->toBe('Z-00003');

    sales(['order_prefix' => 'ORD']);

    expect(app(OrderNumberGenerator::class)->next(OrderType::Order))->toBe('ORD-00004');
});
