<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a wholesale access application (CG-22): new, then approved or rejected.
 */
enum WholesaleApplicationStatus: string
{
    case New = 'new';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return __('wholesale.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
