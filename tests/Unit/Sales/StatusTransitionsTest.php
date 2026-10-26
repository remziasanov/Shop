<?php

declare(strict_types=1);

use App\Enums\OrderStatus as S;
use App\Enums\OrderType as T;
use App\Services\Orders\StatusTransitions;

it('follows the transition table of Appendix B', function (T $type, S $from, array $allowed) {
    $transitions = new StatusTransitions;

    expect($transitions->allowed($type, $from))->toBe($allowed);

    foreach (S::cases() as $to) {
        expect($transitions->canTransition($type, $from, $to))->toBe(in_array($to, $allowed, true));
    }
})->with([
    'order new' => [T::Order, S::New, [S::Confirmed, S::Cancelled]],
    'order confirmed' => [T::Order, S::Confirmed, [S::Ready, S::Shipped, S::Cancelled]],
    'order ready' => [T::Order, S::Ready, [S::Completed, S::Cancelled]],
    'order shipped' => [T::Order, S::Shipped, [S::Completed, S::Cancelled]],
    'order completed' => [T::Order, S::Completed, []],
    'order cancelled' => [T::Order, S::Cancelled, []],
    'reservation new' => [T::Reservation, S::New, [S::Reserved, S::Completed, S::Cancelled]],
    'reservation reserved' => [T::Reservation, S::Reserved, [S::Completed, S::Cancelled]],
    'reservation completed' => [T::Reservation, S::Completed, []],
    'reservation cancelled' => [T::Reservation, S::Cancelled, []],
    'reservation expired' => [T::Reservation, S::Expired, []],
    'inquiry new' => [T::Inquiry, S::New, [S::InProgress, S::Closed]],
    'inquiry in progress' => [T::Inquiry, S::InProgress, [S::Closed]],
    'inquiry closed' => [T::Inquiry, S::Closed, []],
]);

it('lets only the system expire an active reservation', function () {
    $transitions = new StatusTransitions;

    expect($transitions->canTransition(T::Reservation, S::New, S::Expired))->toBeFalse()
        ->and($transitions->canTransition(T::Reservation, S::New, S::Expired, true))->toBeTrue()
        ->and($transitions->canTransition(T::Reservation, S::Reserved, S::Expired, true))->toBeTrue()
        ->and($transitions->canTransition(T::Reservation, S::Completed, S::Expired, true))->toBeFalse()
        ->and($transitions->canTransition(T::Order, S::New, S::Expired, true))->toBeFalse();
});

it('has type specific labels and every status has a colour', function () {
    expect(S::Reserved->label(T::Reservation))->toBe('Зарезервирован (подтверждён)')
        ->and(S::New->label(T::Inquiry))->toBe('Новая')
        ->and(S::New->label(T::Order))->toBe('Новый')
        ->and(S::Completed->label(T::Reservation))->toBe('Выкуплен')
        ->and(S::Completed->label(T::Order))->toBe('Выполнен');

    foreach (S::cases() as $status) {
        expect($status->color())->not->toBe('')->and($status->label())->not->toBe('');
    }
});
