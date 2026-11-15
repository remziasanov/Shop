<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PhoneVerificationError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * A phone verification step was refused (PV-03, PV-05, PV-06). Throttling reasons render as HTTP 429 with `Retry-After`
 * (NF-53), the others are field errors: callers catch the exception and show `$field` / `getMessage()` next to the field
 * (`error()` gives the pair). The message never reveals whether the number exists in the shop (PV-13 ж). Not reported.
 */
class PhoneVerificationException extends RuntimeException
{
    /** Seconds until the action may be repeated; 0 when waiting does not help. */
    public readonly int $retryAfter;

    public function __construct(public readonly PhoneVerificationError $reason, int $retryAfter = 0, ?string $message = null)
    {
        $this->retryAfter = max(0, $retryAfter);

        parent::__construct($message ?? $this->defaultMessage());
    }

    public static function throttled(int $retryAfter, PhoneVerificationError $reason = PhoneVerificationError::Throttled): self
    {
        return new self($reason, max(1, $retryAfter));
    }

    public function field(): string
    {
        return $this->reason->field();
    }

    public function isThrottle(): bool
    {
        return $this->reason->isThrottle();
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): Response|JsonResponse
    {
        $status = $this->isThrottle() ? 429 : 422;
        $headers = ['Cache-Control' => 'no-store, private'] + ($this->retryAfter > 0 ? ['Retry-After' => (string) $this->retryAfter] : []);

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $this->getMessage(), 'errors' => [$this->field() => [$this->getMessage()]]], $status, $headers);
        }

        return response($this->getMessage(), $status, $headers);
    }

    private function defaultMessage(): string
    {
        return match ($this->reason) {
            PhoneVerificationError::Throttled, PhoneVerificationError::NumberLocked, PhoneVerificationError::SmsTooEarly => __('phone_verification.errors.'.$this->reason->value, ['time' => CustomerThrottledException::humanTime(max(1, $this->retryAfter))]),
            default => __('phone_verification.errors.'.$this->reason->value),
        };
    }
}
