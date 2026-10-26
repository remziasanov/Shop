<?php

declare(strict_types=1);

namespace App\Enums;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return __('system.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Ok => 'success',
            self::Warning => 'warning',
            self::Error => 'danger',
        };
    }
}
