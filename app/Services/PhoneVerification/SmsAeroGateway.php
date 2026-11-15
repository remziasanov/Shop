<?php

declare(strict_types=1);

namespace App\Services\PhoneVerification;

use App\Enums\PhoneProviderFailureKind;
use App\Exceptions\PhoneProviderFailure;
use App\Exceptions\PhoneProviderRefusal;
use App\Support\PiiMask;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SMS Aero API v2 (PV-02, D-110): flash call with the client's code, SMS and balance over the Laravel HTTP client.
 * HTTP Basic auth (login = account e-mail, password = API key). Connect <= 3 s, answer <= 5 s, no retries.
 *
 * Endpoints and the answer shape (`{"success": bool, "data": {...}, "message": ...}`) are written from the public API
 * description; the assumptions the owner must check against the live account are listed in docs/DECISIONS.md (A-3).
 * Credentials never reach messages: every text that leaves this class is scrubbed.
 *
 * Classification (PV-05): connection error / timeout, HTTP 5xx and an authorization error are {@see PhoneProviderFailure};
 * an empty balance (HTTP 402 or an «insufficient funds» answer) and every other refusal are {@see PhoneProviderRefusal}.
 */
final class SmsAeroGateway implements PhoneVerificationGateway
{
    public const BASE_URL = 'https://gate.smsaero.ru/v2';

    public const CONNECT_TIMEOUT = 3;

    public const TIMEOUT = 5;

    /** Word-bounded: «включите», «отключена», «подключения» must not match (PV-05). */
    private const AUTH_PATTERN = '/authenticat|unauthori[sz]ed|api[ _-]?key|авторизац|(?<![\p{L}])ключ(?:а|ом|у)?(?![\p{L}])/iu';

    private const BALANCE_PATTERN = '/insufficient|not enough|no money|недостаточно|баланс|средств/iu';

    public function __construct(
        private readonly string $login,
        private readonly string $apiKey,
        private readonly string $sender = '',
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    public function startFlashCall(string $phone, ?string $code): FlashCallResult
    {
        $data = $this->call('POST', '/flashcall/send', ['phone' => $this->digits($phone), 'code' => (string) $code]);

        $requestId = $this->requestId($data);
        $used = (string) ($data['code'] ?? $code ?? '');

        if (preg_match('/^\d{4}$/', $used) !== 1) {
            throw new PhoneProviderRefusal(false, 'flash call answer without a 4-digit code');
        }

        return new FlashCallResult($requestId, $used, $this->cost($data));
    }

    public function sendSms(string $phone, string $text): SmsResult
    {
        $params = ['number' => $this->digits($phone), 'text' => $text];

        if ($this->sender !== '') {
            $params['sign'] = $this->sender;
        }

        $data = $this->call('POST', '/sms/send', $params);

        return new SmsResult($this->requestId($data), $this->cost($data));
    }

    public function balance(): string
    {
        $data = $this->call('GET', '/balance', []);
        $balance = $data['balance'] ?? null;

        if (! is_numeric($balance)) {
            throw new PhoneProviderRefusal(false, 'balance answer without a number');
        }

        return bcadd((string) $balance, '0', 2);
    }

    public function supportsCallerCode(): bool
    {
        return true;
    }

    /**
     * @param  array<string, string>  $params
     * @return array<string, mixed> the `data` object of the answer
     *
     * @throws PhoneProviderFailure
     * @throws PhoneProviderRefusal
     */
    private function call(string $method, string $path, array $params): array
    {
        if ($this->login === '' || $this->apiKey === '') {
            throw new PhoneProviderFailure(PhoneProviderFailureKind::Auth, 'credentials are not set');
        }

        try {
            $request = Http::withBasicAuth($this->login, $this->apiKey)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->acceptJson();

            $url = rtrim($this->baseUrl, '/').$path;
            $response = $method === 'GET' ? $request->get($url, $params) : $request->asForm()->post($url, $params);
        } catch (ConnectionException $e) {
            $message = $this->scrub($e->getMessage());
            $timeout = preg_match('/timed? ?out|timeout|cURL error 28/i', $message) === 1;

            throw new PhoneProviderFailure($timeout ? PhoneProviderFailureKind::Timeout : PhoneProviderFailureKind::Network, $message);
        } catch (TransferException $e) {
            throw new PhoneProviderFailure(PhoneProviderFailureKind::Network, $this->scrub($e::class.': '.$e->getMessage()));
        }

        return $this->parse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(Response $response): array
    {
        $status = $response->status();

        if ($status >= 500) {
            throw new PhoneProviderFailure(PhoneProviderFailureKind::Server, 'HTTP '.$status);
        }

        if ($status === 401 || $status === 403) {
            throw new PhoneProviderFailure(PhoneProviderFailureKind::Auth, 'HTTP '.$status);
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];
        $message = is_string($json['message'] ?? null) ? $this->scrub($json['message']) : '';
        $success = ($json['success'] ?? false) === true;

        if ($success) {
            $data = $json['data'] ?? [];

            return is_array($data) ? $data : [];
        }

        // A zero balance always blocks (PV-05): checked before anything else.
        if ($status === 402 || ($message !== '' && preg_match(self::BALANCE_PATTERN, $message) === 1)) {
            throw new PhoneProviderRefusal(true, $message !== '' ? $message : 'HTTP 402');
        }

        // An authorization error may also come with HTTP 200 and `success: false`; everything else is a refusal.
        if ($message !== '' && preg_match(self::AUTH_PATTERN, $message) === 1) {
            throw new PhoneProviderFailure(PhoneProviderFailureKind::Auth, $message);
        }

        throw new PhoneProviderRefusal(false, $message !== '' ? $message : 'HTTP '.$status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function requestId(array $data): string
    {
        $id = $data['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && $id !== '')) {
            throw new PhoneProviderRefusal(false, 'answer without a request id');
        }

        return (string) $id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function cost(array $data): ?string
    {
        $cost = $data['cost'] ?? null;

        return is_numeric($cost) ? bcadd((string) $cost, '0', 4) : null;
    }

    private function digits(string $phone): string
    {
        return ltrim($phone, '+');
    }

    /**
     * Cuts the credentials (login, API key, their Basic header) out of a text.
     */
    private function scrub(string $text): string
    {
        $secrets = array_filter([$this->apiKey, $this->login, base64_encode($this->login.':'.$this->apiKey)], fn (string $secret): bool => $secret !== '');

        return mb_substr(PiiMask::digitRuns(str_replace($secrets, '***', $text)), 0, 300);
    }
}
