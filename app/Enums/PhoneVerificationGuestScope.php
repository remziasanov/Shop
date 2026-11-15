<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Guest checkout scenario of PV-07: which guest requests need a verified phone.
 */
enum PhoneVerificationGuestScope: string
{
    case Off = 'off';
    case All = 'all';
    case Reservations = 'reservations';
    case OrdersOver = 'orders_over';

    public function label(): string
    {
        return __('phone_verification.guest_checkout.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
