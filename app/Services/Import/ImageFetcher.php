<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Exceptions\ImageFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * SSRF-safe photo download for the import (IM-11, D-035).
 *
 * Per request: only http/https without credentials; the host is resolved through the injectable
 * {@see HostResolver}, every returned address must be public (loopback, private, link-local, reserved,
 * multicast, carrier-grade NAT, unique-local and IPv4-mapped forms are refused) and the connection is pinned
 * to the checked address with CURLOPT_RESOLVE, so DNS rebinding between check and connect is not possible.
 * Redirects are followed manually (at most {@see MAX_REDIRECTS}) and every hop is validated again.
 * Limits: 15 s per request (20 s for the whole fetch), 10 MB (Content-Length, curl progress and the final
 * file size), `Content-Type: image/*` plus a `getimagesize()` check, one retry after a connection error or a
 * 5xx answer (only when the first attempt failed quickly).
 *
 * The body is streamed into the target file (`sink`), never held in memory. Works with `Http::fake()`.
 * Note: when an HTTP proxy is configured in the environment, curl lets the proxy resolve the host.
 */
final class ImageFetcher
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_REDIRECTS = 3;

    public const REQUEST_TIMEOUT = 15.0;

    public const CONNECT_TIMEOUT = 5.0;

    public const TOTAL_TIMEOUT = 20.0;

    /** A failed first attempt is retried only when it failed within this many seconds. */
    private const RETRY_WITHIN = 5.0;

    private const MAX_URL_LENGTH = 2048;

    /** @var list<int> */
    private const ALLOWED_IMAGE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /** @var list<array{string, int}> Blocked IPv4 ranges (network, prefix bits). */
    private const BLOCKED_V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
        ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.88.99.0', 24], ['192.168.0.0', 16],
        ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Download an image into $targetPath.
     *
     * @throws ImageFetchException with a translated message; the target file is removed on failure
     */
    public function fetch(string $url, string $targetPath): void
    {
        try {
            $this->download(trim($url), $targetPath);
        } catch (Throwable $e) {
            @unlink($targetPath);

            throw $e instanceof ImageFetchException ? $e : new ImageFetchException(__('import_process.fetch.connection'), 0, $e);
        }
    }

    /**
     * Whether an IP address is public and routable (the only kind we connect to).
     */
    public static function isPublicAddress(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return self::isPublicV4($packed);
        }

        // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible (::a.b.c.d): judge the embedded IPv4 address.
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return self::isPublicV4(substr($packed, 12, 4));
        }

        if (str_starts_with($packed, str_repeat("\0", 12)) && $packed !== str_repeat("\0", 16) && $packed !== str_repeat("\0", 15)."\x01") {
            return self::isPublicV4(substr($packed, 12, 4));
        }

        $first = ord($packed[0]);

        // Only global unicast 2000::/3 is public; everything else (::/8, fc00::/7, fe80::/10, ff00::/8 …) is not.
        if (($first & 0xE0) !== 0x20) {
            return false;
        }

        // 6to4 (2002::/16) carries an IPv4 address in bits 16–47.
        if (str_starts_with($packed, "\x20\x02")) {
            return self::isPublicV4(substr($packed, 2, 4));
        }

        // 2001::/23 (IETF protocol assignments, Teredo), 2001:db8::/32 (documentation), 3fff::/20 (documentation).
        if (str_starts_with($packed, "\x20\x01") && (ord($packed[2]) & 0xFE) === 0x00) {
            return false;
        }

        if (str_starts_with($packed, "\x20\x01\x0d\xb8")) {
            return false;
        }

        if (str_starts_with($packed, "\x3f\xff") && (ord($packed[2]) & 0xF0) === 0x00) {
            return false;
        }

        return true;
    }

    private static function isPublicV4(string $packed): bool
    {
        $long = (int) sprintf('%u', ip2long(inet_ntop($packed) ?: '0.0.0.0'));

        foreach (self::BLOCKED_V4 as [$network, $bits]) {
            $mask = (-1 << (32 - $bits)) & 0xFFFFFFFF;

            if (($long & $mask) === ((int) sprintf('%u', ip2long($network)) & $mask)) {
                return false;
            }
        }

        return true;
    }

    private function download(string $url, string $targetPath): void
    {
        $deadline = microtime(true) + self::TOTAL_TIMEOUT;
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $target = $this->prepare($current);
            $response = $this->request($target, $targetPath, $deadline);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = trim($response->header('Location'));

                if ($location === '') {
                    throw new ImageFetchException(__('import_process.fetch.http_status', ['status' => $response->status()]));
                }

                $current = $this->absoluteLocation($location, $current);

                continue;
            }

            if (! $response->successful()) {
                throw new ImageFetchException(__('import_process.fetch.http_status', ['status' => $response->status()]));
            }

            $this->assertImage($response, $targetPath);

            return;
        }

        throw new ImageFetchException(__('import_process.fetch.too_many_redirects'));
    }

    /**
     * Validate a URL and resolve it to a pinned, public address.
     *
     * @return array{url: string, host: string, port: int, ip: string|null, literal: bool}
     *
     * @throws ImageFetchException
     */
    private function prepare(string $url): array
    {
        if ($url === '' || strlen($url) > self::MAX_URL_LENGTH || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new ImageFetchException(__('import_process.fetch.invalid_url'));
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new ImageFetchException(__('import_process.fetch.invalid_url'));
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new ImageFetchException(__('import_process.fetch.scheme'));
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new ImageFetchException(__('import_process.fetch.credentials'));
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $host = rtrim($host, '.');

        if ($host === '') {
            throw new ImageFetchException(__('import_process.fetch.invalid_url'));
        }

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = idn_to_ascii($host);
            $host = $ascii !== false ? $ascii : $host;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            throw new ImageFetchException(__('import_process.fetch.dns_failed'));
        }

        foreach ($addresses as $address) {
            if (! self::isPublicAddress($address)) {
                throw new ImageFetchException(__('import_process.fetch.host_blocked'));
            }
        }

        return ['url' => $url, 'host' => $host, 'port' => $port, 'ip' => $addresses[0], 'literal' => $literal];
    }

    /**
     * One request with the connect pin, with a single quick retry.
     *
     * @param  array{url: string, host: string, port: int, ip: string|null, literal: bool}  $target
     *
     * @throws ImageFetchException
     */
    private function request(array $target, string $sink, float $deadline): Response
    {
        $started = microtime(true);

        $attempt = 0;

        while (true) {
            $attempt++;
            $remaining = $deadline - microtime(true);

            if ($remaining < 1.0) {
                throw new ImageFetchException(__('import_process.fetch.connection'));
            }

            $aborted = false;

            try {
                $response = Http::withOptions([
                    'sink' => $sink,
                    'curl' => $this->curlOptions($target),
                    // Truthy return aborts the transfer: the body is capped even without Content-Length.
                    'progress' => static function (int $downloadTotal, int $downloaded) use (&$aborted): bool {
                        if ($downloaded > self::MAX_BYTES || $downloadTotal > self::MAX_BYTES) {
                            $aborted = true;

                            return true;
                        }

                        return false;
                    },
                ])
                    ->withHeaders(['Accept' => 'image/*', 'User-Agent' => 'ShopImporter/1.0'])
                    ->withoutRedirecting()
                    ->connectTimeout(self::CONNECT_TIMEOUT)
                    ->timeout(min(self::REQUEST_TIMEOUT, $remaining))
                    ->get($target['url']);
            } catch (ConnectionException $e) {
                if ($aborted) {
                    throw new ImageFetchException(__('import_process.fetch.too_big', ['max' => intdiv(self::MAX_BYTES, 1048576)]), 0, $e);
                }

                if ($attempt === 1 && microtime(true) - $started < self::RETRY_WITHIN) {
                    continue;
                }

                throw new ImageFetchException(__('import_process.fetch.connection'), 0, $e);
            }

            if ($response->serverError() && $attempt === 1 && microtime(true) - $started < self::RETRY_WITHIN) {
                continue;
            }

            return $response;
        }
    }

    /**
     * @param  array{url: string, host: string, port: int, ip: string|null, literal: bool}  $target
     * @return array<int, mixed>
     */
    private function curlOptions(array $target): array
    {
        $options = [];

        if (! $target['literal'] && $target['ip'] !== null) {
            $ip = str_contains($target['ip'], ':') ? '['.$target['ip'].']' : $target['ip'];
            $options[CURLOPT_RESOLVE] = [$target['host'].':'.$target['port'].':'.$ip];
        }

        return $options;
    }

    private function absoluteLocation(string $location, string $current): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($current);
        $scheme = $parts['scheme'] ?? 'http';
        $authority = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $scheme.'://'.$authority.$location;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $scheme.'://'.$authority.$directory.'/'.$location;
    }

    /**
     * @throws ImageFetchException
     */
    private function assertImage(Response $response, string $path): void
    {
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));

        if (! str_starts_with($type, 'image/') || $type === 'image/svg+xml') {
            throw new ImageFetchException(__('import_process.fetch.not_image'));
        }

        $length = (int) $response->header('Content-Length');

        if ($length > self::MAX_BYTES) {
            throw new ImageFetchException(__('import_process.fetch.too_big', ['max' => intdiv(self::MAX_BYTES, 1048576)]));
        }

        $size = is_file($path) ? filesize($path) : false;

        if ($size === false) {
            throw new ImageFetchException(__('import_process.fetch.storage'));
        }

        if ($size > self::MAX_BYTES) {
            throw new ImageFetchException(__('import_process.fetch.too_big', ['max' => intdiv(self::MAX_BYTES, 1048576)]));
        }

        $info = $size > 0 ? @getimagesize($path) : false;

        if ($info === false || ! in_array($info[2], self::ALLOWED_IMAGE_TYPES, true)) {
            throw new ImageFetchException(__('import_process.fetch.not_image'));
        }
    }
}
