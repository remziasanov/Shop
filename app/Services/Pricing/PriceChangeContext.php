<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\PriceChangeSource;
use Illuminate\Support\Facades\Auth;

/**
 * Who/what is changing prices right now (D-028). Defaults to a manual change by the current user;
 * bulk actions and imports wrap their work in {@see run()}. Register as a singleton.
 */
final class PriceChangeContext
{
    private PriceChangeSource $source = PriceChangeSource::Manual;

    private ?int $importId = null;

    private ?int $userId = null;

    private bool $userOverridden = false;

    public function source(): PriceChangeSource
    {
        return $this->source;
    }

    public function importId(): ?int
    {
        return $this->importId;
    }

    /**
     * The explicit user of the running context, otherwise the authenticated user (null in console/queue).
     */
    public function userId(): ?int
    {
        if ($this->userOverridden) {
            return $this->userId;
        }

        $id = Auth::id();

        return $id !== null ? (int) $id : null;
    }

    /**
     * Run $callback with the given source; the previous context is restored afterwards (also on exceptions).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  int|null  $userId  null keeps the authenticated user
     * @return T
     */
    public function run(PriceChangeSource $source, ?int $importId, callable $callback, ?int $userId = null): mixed
    {
        $previous = [$this->source, $this->importId, $this->userId, $this->userOverridden];

        $this->source = $source;
        $this->importId = $importId;
        $this->userId = $userId;
        $this->userOverridden = $userId !== null;

        try {
            return $callback();
        } finally {
            [$this->source, $this->importId, $this->userId, $this->userOverridden] = $previous;
        }
    }
}
