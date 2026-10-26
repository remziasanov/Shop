<?php

declare(strict_types=1);

namespace App\Services\Import;

/**
 * Resolves a host name to IP addresses for {@see ImageFetcher}. An interface so tests can inject fixed answers.
 */
interface HostResolver
{
    /**
     * IPv4 and IPv6 addresses of the host (an IP literal resolves to itself); empty list when unknown.
     *
     * @return list<string>
     */
    public function resolve(string $host): array;
}
