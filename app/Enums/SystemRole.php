<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Codes of the system roles created by the migration (RB-01, RB-06). Custom roles have free-form codes.
 */
enum SystemRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';

    public function label(): string
    {
        return __('shop.enums.user_role.'.$this->value);
    }
}
