<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a request (Appendix B). The same code can read differently per type:
 * reservation `reserved` is «Зарезервирован (подтверждён)», inquiry `new` is «Новая».
 */
enum OrderStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Ready = 'ready';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Reserved = 'reserved';
    case Expired = 'expired';
    case InProgress = 'in_progress';
    case Closed = 'closed';

    /**
     * Label for the given request type (falls back to the generic label).
     */
    public function label(?OrderType $type = null): string
    {
        if ($type !== null && trans()->has('sales.status.'.$type->value.'.'.$this->value)) {
            return __('sales.status.'.$type->value.'.'.$this->value);
        }

        return __('sales.status.default.'.$this->value);
    }

    /**
     * Filament badge colour.
     */
    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Confirmed, self::Reserved, self::InProgress => 'info',
            self::Ready, self::Shipped => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'danger',
            self::Expired, self::Closed => 'gray',
        };
    }

    /**
     * True when no further transitions exist for any type.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Expired, self::Closed], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(?OrderType $type = null): array
    {
        $options = [];

        foreach ($type?->statuses() ?? self::cases() as $case) {
            $options[$case->value] = $case->label($type);
        }

        return $options;
    }
}
