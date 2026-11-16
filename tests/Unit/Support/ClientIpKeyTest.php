<?php

declare(strict_types=1);

use App\Support\ClientIpKey;

it('keeps an IPv4 address and reduces an IPv6 address to its /64 network (SEC-01, SEC-02)', function (?string $ip, string $key): void {
    expect(ClientIpKey::of($ip))->toBe($key);
})->with([
    'ipv4' => ['203.0.113.7', '203.0.113.7'],
    'ipv6' => ['2001:db8:abcd:12:1:2:3:4', '2001:db8:abcd:12::/64'],
    'ipv6 same /64' => ['2001:db8:abcd:12:ffff:ffff:ffff:ffff', '2001:db8:abcd:12::/64'],
    'ipv6 other /64' => ['2001:db8:abcd:13::1', '2001:db8:abcd:13::/64'],
    'ipv4-mapped' => ['::ffff:198.51.100.9', '198.51.100.9'],
    'empty' => ['', 'unknown'],
    'null' => [null, 'unknown'],
]);
