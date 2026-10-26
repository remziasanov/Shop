<?php

declare(strict_types=1);

use App\Exceptions\ImageFetchException;
use App\Services\Import\HostResolver;
use App\Services\Import\ImageFetcher;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeHostResolver;
use Tests\Support\ImportHarness;

beforeEach(function () {
    $this->target = sys_get_temp_dir().'/fetch-'.bin2hex(random_bytes(4)).'.img';
    $this->bind = function (array $map = []): ImageFetcher {
        app()->instance(HostResolver::class, new FakeHostResolver($map));

        return app(ImageFetcher::class);
    };
});

afterEach(function () {
    @unlink($this->target);
});

function jpegResponse(?string $bytes = null, array $headers = []): PendingRequest|PromiseInterface
{
    return Http::response($bytes ?? ImportHarness::jpeg(), 200, $headers + ['Content-Type' => 'image/jpeg']);
}

it('classifies public and non-public addresses', function (string $ip, bool $public) {
    expect(ImageFetcher::isPublicAddress($ip))->toBe($public);
})->with([
    'public v4' => ['93.184.216.34', true],
    'public v4 b' => ['8.8.8.8', true],
    'loopback' => ['127.0.0.1', false],
    'loopback range' => ['127.255.0.9', false],
    'zero net' => ['0.0.0.0', false],
    'zero net b' => ['0.1.2.3', false],
    'private 10' => ['10.0.0.5', false],
    'private 172' => ['172.16.5.5', false],
    'private 172 edge' => ['172.31.255.255', false],
    'public 172' => ['172.32.0.1', true],
    'private 192' => ['192.168.1.1', false],
    'cgnat' => ['100.64.0.1', false],
    'cgnat end' => ['100.127.255.255', false],
    'link-local metadata' => ['169.254.169.254', false],
    'multicast' => ['224.0.0.1', false],
    'reserved' => ['240.0.0.1', false],
    'broadcast' => ['255.255.255.255', false],
    'test-net' => ['192.0.2.10', false],
    'v6 loopback' => ['::1', false],
    'v6 unspecified' => ['::', false],
    'v6 unique local' => ['fc00::1', false],
    'v6 unique local fd' => ['fd12:3456::1', false],
    'v6 link-local' => ['fe80::1', false],
    'v6 multicast' => ['ff02::1', false],
    'v4-mapped loopback' => ['::ffff:127.0.0.1', false],
    'v4-mapped private' => ['::ffff:10.0.0.1', false],
    'v4-mapped hex' => ['::ffff:a00:1', false],
    'v4-mapped public' => ['::ffff:8.8.8.8', true],
    'v4-compatible loopback' => ['::127.0.0.1', false],
    '6to4 private' => ['2002:0a00:0001::1', false],
    'documentation' => ['2001:db8::1', false],
    'public v6' => ['2606:4700:4700::1111', true],
    'garbage' => ['not-an-ip', false],
]);

it('downloads an image from a public host', function () {
    Http::fake(['https://img.test/*' => jpegResponse()]);

    ($this->bind)()->fetch('https://img.test/a.jpg', $this->target);

    expect(getimagesize($this->target)[2])->toBe(IMAGETYPE_JPEG);
    Http::assertSentCount(1);
});

it('blocks hosts that resolve to non-public addresses without sending a request', function (string $ip) {
    Http::fake();

    expect(fn () => ($this->bind)(['evil.test' => [$ip]])->fetch('http://evil.test/a.jpg', $this->target))
        ->toThrow(ImageFetchException::class);

    Http::assertNothingSent();
    expect(file_exists($this->target))->toBeFalse();
})->with(['127.0.0.1', '10.0.0.5', '169.254.169.254', '::1', '192.168.0.1', '::ffff:127.0.0.1', 'fc00::5']);

it('blocks a host when any of its addresses is private', function () {
    Http::fake();

    expect(fn () => ($this->bind)(['mixed.test' => ['93.184.216.34', '10.0.0.5']])->fetch('http://mixed.test/a.jpg', $this->target))
        ->toThrow(ImageFetchException::class);

    Http::assertNothingSent();
});

it('blocks IP literals and unsupported schemes', function (string $url) {
    Http::fake();

    expect(fn () => ($this->bind)()->fetch($url, $this->target))->toThrow(ImageFetchException::class);

    Http::assertNothingSent();
})->with([
    'http://127.0.0.1/a.jpg',
    'http://[::1]/a.jpg',
    'http://169.254.169.254/latest/meta-data',
    'http://0.0.0.0/a.jpg',
    'file:///etc/passwd',
    'ftp://img.test/a.jpg',
    'gopher://img.test/',
    'http://user:pass@img.test/a.jpg',
    'img.test/a.jpg',
    '',
    'http://img.test/a b.jpg',
]);

it('blocks a redirect to a private address', function () {
    Http::fake([
        'http://img.test/*' => Http::response('', 302, ['Location' => 'http://internal.test/secret.jpg']),
        'http://internal.test/*' => jpegResponse(),
    ]);

    expect(fn () => ($this->bind)(['internal.test' => ['10.0.0.5']])->fetch('http://img.test/a.jpg', $this->target))
        ->toThrow(ImageFetchException::class);

    Http::assertSentCount(1);
});

it('blocks a redirect to a loopback IP literal and to another scheme', function (string $location) {
    Http::fake(['http://img.test/*' => Http::response('', 301, ['Location' => $location])]);

    expect(fn () => ($this->bind)()->fetch('http://img.test/a.jpg', $this->target))->toThrow(ImageFetchException::class);

    Http::assertSentCount(1);
})->with(['http://127.0.0.1:8080/x.jpg', 'file:///etc/passwd', 'http://[::1]/x']);

it('follows up to three redirects, relative ones included, and re-validates every hop', function () {
    Http::fake([
        'http://img.test/a.jpg' => Http::response('', 302, ['Location' => '/b.jpg']),
        'http://img.test/b.jpg' => Http::response('', 301, ['Location' => 'https://cdn.test/c.jpg']),
        'https://cdn.test/c.jpg' => jpegResponse(),
    ]);

    ($this->bind)()->fetch('http://img.test/a.jpg', $this->target);

    expect(file_exists($this->target))->toBeTrue();
    Http::assertSentCount(3);
});

it('gives up after more than three redirects', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://img.test/next.jpg'])]);

    expect(fn () => ($this->bind)()->fetch('http://img.test/a.jpg', $this->target))->toThrow(ImageFetchException::class);

    Http::assertSentCount(4);
});

it('requests the original URL (the pin only affects name resolution)', function () {
    Http::fake(['*' => jpegResponse()]);

    ($this->bind)(['img.test' => ['93.184.216.34']])->fetch('https://img.test:8443/a.jpg', $this->target);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://img.test:8443/a.jpg');
});

it('rejects non-image content types, svg and bodies that are not images', function (string $type, string $body) {
    Http::fake(['*' => Http::response($body, 200, ['Content-Type' => $type])]);

    expect(fn () => ($this->bind)()->fetch('http://img.test/a.jpg', $this->target))->toThrow(ImageFetchException::class);

    expect(file_exists($this->target))->toBeFalse();
})->with([
    'html' => ['text/html', '<html></html>'],
    'svg' => ['image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
    'octet' => ['application/octet-stream', 'GIF89a'],
    'fake image' => ['image/jpeg', 'definitely not an image'],
    'empty' => ['image/jpeg', ''],
]);

it('rejects images over 10 MB', function () {
    Http::fake(['*' => Http::response(str_repeat('a', ImageFetcher::MAX_BYTES + 10), 200, ['Content-Type' => 'image/jpeg'])]);

    expect(fn () => ($this->bind)()->fetch('http://img.test/a.jpg', $this->target))
        ->toThrow(ImageFetchException::class, '10');

    expect(file_exists($this->target))->toBeFalse();
});

it('rejects a declared Content-Length over 10 MB', function () {
    Http::fake(['*' => Http::response(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) (ImageFetcher::MAX_BYTES + 1)])]);

    expect(fn () => ($this->bind)()->fetch('http://img.test/a.jpg', $this->target))->toThrow(ImageFetchException::class);
});

it('retries once after a server error', function () {
    Http::fake(['*' => Http::sequence()->push('', 503)->push(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    ($this->bind)()->fetch('http://img.test/a.jpg', $this->target);

    Http::assertSentCount(2);
    expect(file_exists($this->target))->toBeTrue();
});

it('retries once after a connection error', function () {
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->push(ImportHarness::jpeg(), 200, ['Content-Type' => 'image/jpeg'])]);

    ($this->bind)()->fetch('http://img.test/b.jpg', $this->target);

    Http::assertSentCount(2);
});

it('fails after the single retry of a server error', function () {
    Http::fake(['*' => Http::response('', 500)]);

    expect(fn () => ($this->bind)()->fetch('http://img.test/a.jpg', $this->target))->toThrow(ImageFetchException::class);

    Http::assertSentCount(2);
});

it('does not retry client errors', function () {
    Http::fake(['*' => Http::response('', 404)]);

    expect(fn () => ($this->bind)()->fetch('http://img.test/b.jpg', $this->target))->toThrow(ImageFetchException::class, '404');

    Http::assertSentCount(1);
});

it('fails with a safe message when the connection keeps failing', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 7: secret internal detail')]);

    expect(fn () => ($this->bind)()->fetch('http://img.test/c.jpg', $this->target))
        ->toThrow(fn (ImageFetchException $e) => expect($e->getMessage())->not->toContain('secret'));
});

it('fails when the host does not resolve', function () {
    Http::fake();

    expect(fn () => ($this->bind)(['nowhere.test' => []])->fetch('http://nowhere.test/a.jpg', $this->target))
        ->toThrow(ImageFetchException::class);
});
