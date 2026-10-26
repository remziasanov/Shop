<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Import\HostResolver;

/**
 * Fixed DNS answers for tests: host => addresses; unknown hosts resolve to a public address.
 */
final class FakeHostResolver implements HostResolver
{
    /**
     * @param  array<string, list<string>>  $map
     */
    public function __construct(private array $map = [], private readonly string $default = '93.184.216.34') {}

    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->map[$host] ?? [$this->default];
    }
}
