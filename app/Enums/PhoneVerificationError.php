<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a verification step was refused (PhoneVerificationException::$reason). Throttling reasons answer with HTTP 429,
 * the others are field errors (`phone` or `code`).
 */
enum PhoneVerificationError: string
{
    case Disabled = 'disabled';
    case InvalidPhone = 'invalid_phone';
    case PrefixNotAllowed = 'prefix_not_allowed';
    case Throttled = 'throttled';
    case NumberLocked = 'number_locked';
    case DailyLimit = 'daily_limit';
    case ProviderUnavailable = 'provider_unavailable';
    case ProviderRefused = 'provider_refused';
    case NoActiveCode = 'no_active_code';
    case WrongCode = 'wrong_code';
    case Expired = 'expired';
    case TooManyAttempts = 'too_many_attempts';
    case SmsNotAvailable = 'sms_not_available';
    case SmsTooEarly = 'sms_too_early';

    public function isThrottle(): bool
    {
        return in_array($this, [self::Throttled, self::NumberLocked, self::SmsTooEarly], true);
    }

    /**
     * The form field the message belongs to.
     */
    public function field(): string
    {
        return match ($this) {
            self::NoActiveCode, self::WrongCode, self::Expired, self::TooManyAttempts => 'code',
            default => 'phone',
        };
    }
}
