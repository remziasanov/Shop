<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of one verification record (PV-08): `pending` = sent, the code has not been entered yet.
 */
enum PhoneVerificationOutcome: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Expired = 'expired';
    case SkippedProvider = 'skipped_provider';
    case Blocked = 'blocked';

    public function label(): string
    {
        return __('phone_verification.outcomes.'.$this->value);
    }
}
