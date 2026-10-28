<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * A customer-account limit was hit (CA-12, NF-53). It renders itself as HTTP 429 with the waiting time in the
 * message and the `Retry-After` header, and is not reported: it is expected behaviour, not an error.
 */
class CustomerThrottledException extends RuntimeException
{
    /** Seconds until the action is possible again (at least 1). */
    public readonly int $retryAfter;

    public function __construct(int $retryAfter)
    {
        $this->retryAfter = max(1, $retryAfter);

        parent::__construct(__('account.errors.throttled', ['time' => self::humanTime($this->retryAfter)]));
    }

    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): Response|JsonResponse
    {
        $headers = ['Retry-After' => (string) $this->retryAfter, 'Cache-Control' => 'no-store, private'];

        if ($request->expectsJson()) {
            return new JsonResponse(['message' => $this->getMessage()], 429, $headers);
        }

        return response()->view('storefront.account.throttled', ['message' => $this->getMessage()], 429, $headers);
    }

    /**
     * «45 сек.», «15 мин.», «2 ч.» (rounded up, so the shown time is never too short).
     */
    public static function humanTime(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => __('account.time.seconds', ['count' => $seconds]),
            $seconds < 3600 => __('account.time.minutes', ['count' => (int) ceil($seconds / 60)]),
            default => __('account.time.hours', ['count' => (int) ceil($seconds / 3600)]),
        };
    }
}
